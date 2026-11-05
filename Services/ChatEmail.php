<?php

namespace Modules\WordPressChat\Services;

use App\Thread;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\WordPressChat;

/**
 * The context of the one chat email being built in this process (Task 4's
 * job sets it around core's send) and the four core email hooks that read
 * it. Static state: callers must always end() in a finally.
 */
class ChatEmail
{
    const TEXT_TEMPLATE = 'wordpresschat::emails/reply_text';

    private static $current = null;

    private static $innerTemplate = 'emails/customer/reply_fancy_text';

    public static function begin(int $conversationId, array $threadIds, ?string $link): void
    {
        self::$current       = [
            'conversation_id' => $conversationId,
            'thread_ids'      => array_values(array_map('intval', $threadIds)),
            'link'            => $link,
        ];
        self::$innerTemplate = 'emails/customer/reply_fancy_text';
    }

    public static function end(): void
    {
        self::$current       = null;
        self::$innerTemplate = 'emails/customer/reply_fancy_text';
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    public static function innerTextTemplate(): string
    {
        return self::$innerTemplate;
    }

    private static function applies($conversation): bool
    {
        return self::$current !== null
            && $conversation
            && (int) $conversation->id === (int) self::$current['conversation_id'];
    }

    /**
     * Core's history setting may leave out batch replies; add them back.
     */
    public static function threads($threads, $conversation)
    {
        if (!self::applies($conversation)) {
            return $threads;
        }

        $have = $threads->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $missing = array_diff(self::$current['thread_ids'], $have);

        if (!$missing) {
            return $threads;
        }

        $extra = Thread::where('conversation_id', $conversation->id)
            ->whereIn('id', $missing)
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('state', Thread::STATE_PUBLISHED)
            ->get();

        if ($extra->isEmpty()) {
            return $threads;
        }

        return Thread::sortThreads($threads->concat($extra))->values();
    }

    /**
     * Ruling E10: core's mailable attaches only its last_thread's files
     * ($thread here); the files of the batch's other replies are added to
     * the Swift message. Read from local storage only: with remote storage
     * (or a file gone from disk) a file is skipped with a log line, and the
     * email still goes out.
     */
    public static function attachBatchFiles($swiftmessage, $thread): void
    {
        if (self::$current === null
            || !$swiftmessage instanceof \Swift_Mime_SimpleMessage
            || !$thread instanceof Thread
            || (int) $thread->conversation_id !== (int) self::$current['conversation_id']
        ) {
            return;
        }

        $others = array_values(array_diff(self::$current['thread_ids'], [(int) $thread->id]));

        if (!$others) {
            return;
        }

        $threads = Thread::where('conversation_id', $thread->conversation_id)
            ->whereIn('id', $others)
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('state', Thread::STATE_PUBLISHED)
            ->where('has_attachments', true)
            ->orderBy('id')
            ->get();

        foreach ($threads as $reply) {
            // Core's own choice for an email: the non-embedded files.
            foreach ($reply->attachments()->orderBy('id')->get() as $attachment) {
                if (!\Helper::isLocalStorage()) {
                    \Log::warning('[WordPressChat] Chat email for conversation #'.$thread->conversation_id
                        .': attachment #'.$attachment->id.' of reply #'.$reply->id.' skipped, storage is not local.');
                    continue;
                }

                if (!$attachment->fileExists()) {
                    \Log::error('[WordPressChat] Chat email for conversation #'.$thread->conversation_id
                        .': attachment #'.$attachment->id.' of reply #'.$reply->id.' is missing on disk: '.$attachment->getLocalFilePath());
                    continue;
                }

                $swiftmessage->attach(
                    \Swift_Attachment::fromPath($attachment->getLocalFilePath())->setFilename($attachment->file_name)
                );
            }
        }
    }

    public static function echoLink($loop, $conversation): void
    {
        if (!self::applies($conversation) || empty(self::$current['link']) || empty($loop->first)) {
            return;
        }

        echo '<p style="margin:12px 0 0 0;"><a href="'.e(self::$current['link']).'">'
            .e(__('Continue this chat on the website')).'</a></p>';
    }

    /**
     * Core threads an email to the visitor's last customer email, and a
     * chat has none: every fallback email would start a new thread in the
     * visitor's mail client. A website-chat email without In-Reply-To
     * answers the newest earlier reply that went out as an email (the last
     * thread of the previous batch carried that email's Message-ID).
     */
    public static function threadToPreviousEmail($swiftmessage, $thread, $mailbox = null): void
    {
        if (!$swiftmessage instanceof \Swift_Mime_SimpleMessage
            || !$thread instanceof Thread
            || $swiftmessage->getHeaders()->has('In-Reply-To')
        ) {
            return;
        }

        $conversation = $thread->conversation;

        if (!$conversation || !WordPressChat::isWebsiteChat($conversation)) {
            return;
        }

        $previousId = Message::where('conversation_id', $conversation->id)
            ->where('email_status', Message::EMAIL_SENT)
            ->where('thread_id', '<', $thread->id)
            ->max('thread_id');

        $previous = $previousId ? Thread::find($previousId) : null;
        $mailbox = $mailbox ?: $conversation->mailbox;

        if (!$previous || !$mailbox) {
            return;
        }

        $messageId = $previous->getMessageId($mailbox);

        if ($messageId) {
            $headers = $swiftmessage->getHeaders();
            $headers->addTextHeader('In-Reply-To', '<'.$messageId.'>');
            $headers->addTextHeader('References', '<'.$messageId.'>');
        }
    }

    /**
     * The text part with the continue link, above core's branding block
     * ("Support powered by") when the part has one, else at the end.
     */
    public static function textWithLink(string $text): string
    {
        $link = self::$current['link'] ?? null;

        if (!$link) {
            return $text;
        }

        $rule  = str_repeat('-', 59);
        $block = $rule."\n".__('Continue this chat on the website').":\n".$link."\n";
        // Core's template ends with the branding block when the option is
        // on: the last rule line of the part (reply separators are rules too).
        $at = \App\Option::get('email_branding') ? strrpos($text, $rule."\n") : false;

        if ($at === false) {
            return rtrim($text, "\n")."\n\n".$block;
        }

        return substr($text, 0, $at).$block."\n".substr($text, $at);
    }

    public static function textTemplate($name)
    {
        // The template filter has no conversation; the context alone gates it.
        if (self::$current === null) {
            return $name;
        }

        self::$innerTemplate = $name;

        return self::TEXT_TEMPLATE;
    }
}
