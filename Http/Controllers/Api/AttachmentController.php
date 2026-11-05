<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use App\Attachment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Services\ChatAttachment;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\Services\VisitorAuth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * GET …/attachments/{id} with X-Chat-Token: the file of an attachment
     * whose thread has a message row this browser may see
     * (Visibility::query(), spec §6.4), streamed. The widget fetches it
     * with its token and shows it through a blob URL (Ruling E4): no URL
     * works without the token.
     *
     * 401 without a token the site knows. Then one answer, 404 not_found,
     * for an unknown id, an id beyond the column's range, a file on no
     * thread, a note, a draft, an unpublished reply, another browser's,
     * conversation's or site's file, and a file missing from storage: the
     * answer never tells which.
     */
    public function show(Request $request)
    {
        $site    = $request->attributes->get('wordpresschat.site');
        $session = VisitorAuth::resolve($site, $request->header('X-Chat-Token'));

        if (!$session) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // The route allows 1-10 digits; the column is a 4-byte integer, and
        // PostgreSQL rejects a larger bound parameter (22003): bound first.
        $id = (string) $request->route('id');
        if (!ctype_digit($id) || strlen($id) > 10 || (int) $id < 1 || (int) $id > ChatController::MAX_ID) {
            return $this->notFound();
        }

        $attachment = Attachment::find((int) $id);

        if (!$attachment || !$attachment->thread_id) {
            return $this->notFound();
        }

        $visible = Visibility::query($session)
            ->where('wordpresschat_messages.thread_id', (int) $attachment->thread_id)
            ->exists();

        if (!$visible) {
            return $this->notFound();
        }

        return $this->stream($attachment) ?: $this->notFound();
    }

    /**
     * The file as a streamed response, or null when storage can't read it.
     * A Symfony response keeps its own Cache-Control: core's ResponseHeaders
     * middleware rewrites that header only on Laravel responses.
     */
    private function stream(Attachment $attachment)
    {
        // Core logs its own failure; this warning names the attachment.
        $stream = $attachment->getFileStream();

        if (!is_resource($stream)) {
            \Log::warning('[WordPressChat] Attachment #'.$attachment->id.' is missing from storage: '.$attachment->getStorageFilePath());

            return null;
        }

        $headers = [
            'Content-Type'            => ChatAttachment::servedMime($attachment),
            'Content-Disposition'     => ChatAttachment::contentDisposition($attachment),
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, no-store',
            // Opened directly, the file runs nothing and loads nothing.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $stat = @fstat($stream);
        if (is_array($stat) && isset($stat['size']) && $stat['size'] > 0) {
            $headers['Content-Length'] = (string) $stat['size'];
        }

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    private function notFound()
    {
        return response()->json(['error' => 'not_found'], 404);
    }
}
