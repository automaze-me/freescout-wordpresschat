<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Customer;
use App\Jobs\SendReplyToCustomer;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Collection;

/**
 * The SMTP boundary: core's own reply email, run inline (Ruling D6), so
 * threading, the mailbox's SMTP settings, the send log and the IMAP copy
 * stay core's. Tests replace this class with a fake.
 *
 * Inline, core's job has no queue job: attempts() is 1, release() and
 * fail() do nothing. A transport error core would retry is rethrown and
 * reaches the caller; one it never retries (`app.no_retry_mail_errors`)
 * ends as STATUS_SEND_ERROR on the thread without a throw.
 */
class CoreReplyMailer
{
    /**
     * Null when the email was accepted, otherwise why not. Lets a
     * \Throwable from core's job through.
     */
    public function send(Conversation $conversation, Collection $replies, Customer $customer): ?string
    {
        $root = \Mail::getFacadeRoot();
        $before = count($root->failures());

        (new SendReplyToCustomer($conversation, $replies, $customer))->handle();

        if (self::refused($conversation, $root, $before)) {
            return __("the mail server refused the visitor's address");
        }

        // Core reports on the newest reply (its last_thread), saved by its job.
        $newest = Thread::getLastThread($replies);
        $thread = $newest ? Thread::find($newest->id) : null;

        if (!$thread) {
            return __('not sent');
        }

        if ($thread->send_status == SendLog::STATUS_ACCEPTED || $thread->isSendStatusSuccess()) {
            return null;
        }

        if ($thread->send_status == SendLog::STATUS_SEND_ERROR) {
            $data = $thread->getSendStatusData();

            return !empty($data['msg']) ? (string) $data['msg'] : __('send error');
        }

        // An early return in core's job: mailbox deleted, reply undone, no thread.
        return __('not sent');
    }

    /**
     * Whether the mail server refused the conversation's address at send
     * time. Swift records a refused recipient and does not throw, so core
     * still marks the thread accepted. Laravel's mailer keeps its failures
     * for as long as the mail config is unchanged: only this send's count,
     * or all of a mailer that core's job has just rebuilt.
     */
    private static function refused(Conversation $conversation, $before, int $count): bool
    {
        $after = \Mail::getFacadeRoot();
        $failures = (array) $after->failures();

        if ($after === $before) {
            $failures = array_slice($failures, $count);
        }

        $address = strtolower(trim((string) $conversation->customer_email));

        return $address !== '' && in_array($address, array_map('strtolower', array_map('strval', $failures)), true);
    }
}
