<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\ChatAttachment;

/**
 * A visitor's send larger than PHP's post_max_size (spec §4: a message
 * never disappears silently).
 *
 * Laravel's global ValidatePostSize answers such a request with 413 before
 * any route runs, so the route's Cors middleware never adds its headers. The
 * widget's cross-origin fetch then sees a network error and offers a retry
 * that can never succeed. The module's provider prepends this middleware to
 * the global stack, ahead of ValidatePostSize.
 *
 * Only a POST to a known site's messages endpoint whose Content-Length
 * exceeds post_max_size is answered here: with what the controller answers
 * for a file over the limit (422 too_large), passed through Cors, so the
 * site's origin gets exactly the route's CORS headers and any other origin
 * gets Cors's 403 without them. Every other request passes through.
 *
 * PHP drops an oversized body before any code runs, so nothing is read
 * from it. With display_startup_errors on, PHP also prints its warning
 * about the body first, and no header can be set after that; production
 * PHP has it off.
 */
class OversizedUpload
{
    /** The messages endpoint below the subdirectory; group 1 is the site's public id. */
    const PATH = 'wordpress-chat/api/v1/sites/([a-f0-9]{32})/messages';

    public function handle($request, Closure $next)
    {
        // Laravel's own maintenance check runs later in the stack and answers.
        if (!$request->isMethod('POST') || app()->isDownForMaintenance()) {
            return $next($request);
        }

        // ValidatePostSize's test: the declared length against post_max_size.
        $max = ChatAttachment::iniBytes((string) ini_get('post_max_size'));
        if ($max <= 0 || (int) $request->server('CONTENT_LENGTH') <= $max) {
            return $next($request);
        }

        $publicId = self::publicId($request);

        try {
            $site = $publicId === null ? null : Site::where('public_id', $publicId)->first();
        } catch (QueryException $e) {
            // A database outage: the same 503 the route's RequireSchema gives.
            // No site is known, so no CORS headers.
            return $this->withHeaders(RequireSchema::maintenance());
        }

        if (!$site) {
            return $next($request);
        }

        // What ResolveSite hands to Cors on the route.
        $request->attributes->set('wordpresschat.site', $site);

        $response = (new Cors())->handle($request, function () {
            return response()->json(['error' => 'validation', 'field' => 'file', 'reason' => 'too_large'], 422);
        });

        return $this->withHeaders($response);
    }

    /** The headers the global ResponseHeaders middleware would add; it never sees this answer. */
    private function withHeaders($response)
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-cache, max-age=0, must-revalidate, no-store');
        $response->headers->set('Pragma', 'no-cache');

        if (\Helper::isHttps()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    /**
     * The site's public id when the request path is the messages endpoint,
     * under the same subdirectory prefix the module's routes carry.
     */
    private static function publicId($request): ?string
    {
        $prefix = trim((string) \Helper::getSubdirectory(), '/');
        $pattern = '#\A'.($prefix === '' ? '' : preg_quote($prefix, '#').'/').self::PATH.'\z#';

        return preg_match($pattern, rawurldecode($request->path()), $m) ? $m[1] : null;
    }
}
