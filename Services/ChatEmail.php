<?php

namespace Modules\WordPressChat\Services;

use App\Thread;

/**
 * The context of the one chat email being built in this process (Task 4's
 * job sets it around core's send) and the three core email hooks that read
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

    public static function echoLink($loop, $conversation): void
    {
        if (!self::applies($conversation) || empty(self::$current['link']) || empty($loop->first)) {
            return;
        }

        echo '<p style="margin:12px 0 0 0;"><a href="'.e(self::$current['link']).'">'
            .e(__('Continue this chat on the website')).'</a></p>';
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
