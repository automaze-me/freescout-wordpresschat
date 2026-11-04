<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    const DIRECTION_IN  = 1;
    const DIRECTION_OUT = 2;

    /** Values of `via`: the channel the thread travelled by. */
    const VIA_CHAT  = 1;
    const VIA_EMAIL = 2;

    /** Values of `email_status`: the fallback or chosen email. */
    const EMAIL_QUEUED  = 1;
    const EMAIL_SENDING = 2;
    const EMAIL_SENT    = 3;
    const EMAIL_FAILED  = 4;

    protected $table = 'wordpresschat_messages';

    protected $guarded = ['id'];

    protected $dates = ['published_at', 'seen_at', 'fallback_due_at', 'lost_at', 'created_at', 'updated_at'];

    public function thread()
    {
        return $this->belongsTo('App\Thread');
    }

    public function conversation()
    {
        return $this->belongsTo('App\Conversation');
    }
}
