<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\WordPressChat;

/**
 * Hooks core's `conversation.merged` (Conversation::mergeConversations()):
 * every thread of the website chat $source now sits in $target, and $source
 * is in the trash. Our rows keep the source's conversation_id, so the
 * fallback sweep, which only mails published conversations, would never
 * send the replies the visitor had not seen.
 *
 * They are not lost silently (spec anti-goal): every outbound row of the
 * source whose reply is published and that is neither seen, lost, nor
 * sent, and not being sent, fails and loses its due time, and $target gets
 * one red line item. A queued job for them then claims nothing. Rows being
 * sent ("sending") are left to the job.
 *
 * A reply still inside core's undo window has no row yet: its publish
 * runs after the merge, and PublishReplyToChat fails it the same way,
 * unless one browser holds both chats (Ruling P5).
 *
 * Re-pointing the rows and the browsers' access to $target is not done
 * here: it changes who can see what.
 */
class MergeChat
{
    public static function handle($target, $source, $user = null): void
    {
        try {
            if (!$target instanceof Conversation
                || !$source instanceof Conversation
                || !WordPressChat::isWebsiteChat($source)) {
                return;
            }

            // One conditional statement: a row the sweep queues or a "seen"
            // marks meanwhile is either taken whole or not at all. Published
            // replies only: an undone email choice (its thread a draft
            // again) keeps a row that was never meant to reach anyone.
            $failed = Message::where('conversation_id', $source->id)
                ->where('direction', Message::DIRECTION_OUT)
                ->whereHas('thread', function ($q) {
                    $q->where('state', Thread::STATE_PUBLISHED);
                })
                ->whereNull('seen_at')
                ->whereNull('lost_at')
                ->where(function ($q) {
                    $q->whereNull('email_status')
                        ->orWhere('email_status', Message::EMAIL_QUEUED);
                })
                ->update([
                    'email_status'    => Message::EMAIL_FAILED,
                    'fallback_due_at' => null,
                ]);

            if (!$failed) {
                return;
            }

            FailureLineItem::create($target, self::failureText($failed));
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] MergeChat: listener failed', [
                'target_id' => is_object($target) ? ($target->id ?? null) : null,
                'source_id' => is_object($source) ? ($source->id ?? null) : null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * The red line item for $count replies of a merged chat that reached
     * the visitor neither by chat nor by email. Also written by
     * PublishReplyToChat for a reply published after its chat was merged.
     */
    public static function failureText(int $count): string
    {
        // Laravel 5.5's trans_choice() reads no JSON translations:
        // __() translates the line, trans_choice() picks the form.
        $line = __('A website chat reply had not reached the visitor when this conversation was merged. It was not sent by email.|:count website chat replies had not reached the visitor when this conversation was merged. They were not sent by email.');

        return trans_choice($line, $count);
    }
}
