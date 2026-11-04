<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    const DIRECTION_IN  = 1;
    const DIRECTION_OUT = 2;

    protected $table = 'wordpresschat_messages';

    protected $guarded = ['id'];

    protected $dates = ['published_at', 'seen_at', 'created_at', 'updated_at'];

    public function thread()
    {
        return $this->belongsTo('App\Thread');
    }

    public function conversation()
    {
        return $this->belongsTo('App\Conversation');
    }
}
