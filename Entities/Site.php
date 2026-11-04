<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $table = 'wordpresschat_sites';

    protected $guarded = ['id'];

    protected $dates = ['created_at', 'updated_at'];

    public function getSecret(): string
    {
        return decrypt($this->secret);
    }

    public function setSecret(string $plain): void
    {
        $this->secret = encrypt($plain);
    }

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }
}
