<?php

namespace Modules\WordPressChat\Services;

use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\WordPressChat;

/**
 * The one-line delivery marker under an agent reply (spec 8.2, Ruling D11).
 * Precedence: lost, failed, sent by email, seen, sending by email, waiting.
 */
class DeliveryMarker
{
    /**
     * conversation_id => array<thread_id, Message>: one query per
     * conversation per request, not one per thread.
     *
     * @var array<int, array<int, Message>>
     */
    private static $cache = [];

    /** Tests only. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    public static function render($thread, $loop, $threads, $conversation, $mailbox): void
    {
        if ((int) $thread->type !== \App\Thread::TYPE_MESSAGE
            || !$conversation instanceof \App\Conversation
            || !WordPressChat::isWebsiteChat($conversation)) {
            return;
        }

        if (!isset(self::$cache[$conversation->id])) {
            self::$cache[$conversation->id] = Message::where('conversation_id', $conversation->id)
                ->where('direction', Message::DIRECTION_OUT)
                ->whereNotNull('thread_id')
                ->get()
                ->keyBy('thread_id')
                ->all();
        }

        if (!isset(self::$cache[$conversation->id][$thread->id])) {
            return;
        }

        $marker = self::marker(self::$cache[$conversation->id][$thread->id]);

        if ($marker === null) {
            return;
        }

        list($icon, $text) = $marker;

        echo '<div class="thread-meta"><i class="glyphicon '.e($icon).'"></i> '.e($text).'</div>';
    }

    /**
     * @return array|null [icon classes, text], or null when there is nothing to report
     */
    private static function marker(Message $row)
    {
        $status = (int) $row->email_status;
        $byChoice = (int) $row->via === Message::VIA_EMAIL;

        if ($row->lost_at) {
            return ['glyphicon-exclamation-sign text-danger', __('Not delivered to the chat')];
        }

        if ($status === Message::EMAIL_FAILED) {
            return ['glyphicon-exclamation-sign text-danger', __('Email failed')];
        }

        if ($status === Message::EMAIL_SENT) {
            return ['glyphicon-envelope', $byChoice
                ? __("Sent by email (agent's choice)")
                : __('Sent by email (visitor had left the page)')];
        }

        if ($row->seen_at) {
            return ['glyphicon-ok', __('Seen in chat')];
        }

        if ($status === Message::EMAIL_QUEUED || $status === Message::EMAIL_SENDING) {
            return ['glyphicon-envelope', $byChoice
                ? __("Sending by email (agent's choice)")
                : __('Sending by email (visitor had left the page)')];
        }

        if ($row->published_at && !$byChoice) {
            return ['glyphicon-time', __('Waiting to be seen')];
        }

        return null;
    }
}
