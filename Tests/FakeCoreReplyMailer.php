<?php

namespace Modules\WordPressChat\Tests;

use App\Conversation;
use App\Customer;
use Illuminate\Support\Collection;
use Modules\WordPressChat\Services\ChatEmail;
use Modules\WordPressChat\Services\CoreReplyMailer;

/**
 * The SMTP boundary in tests: never runs core's mail job. Records each call
 * with the email context that was set while it ran.
 */
class FakeCoreReplyMailer extends CoreReplyMailer
{
    /** Each call: conversation_id, first_reply_id, context (ChatEmail::current()). */
    public $calls = [];

    /** null (sent), a string (permanent failure) or a \Throwable to throw. */
    private $outcome;

    /** Runs during the send, after the call is recorded. */
    private $during;

    public function __construct($outcome = null, ?callable $during = null)
    {
        $this->outcome = $outcome;
        $this->during = $during;
    }

    public function send(Conversation $conversation, Collection $replies, Customer $customer): ?string
    {
        $first = $replies->first();

        $this->calls[] = [
            'conversation_id' => (int) $conversation->id,
            'first_reply_id'  => $first ? (int) $first->id : null,
            'context'         => ChatEmail::current(),
        ];

        if ($this->during) {
            call_user_func($this->during);
        }

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
