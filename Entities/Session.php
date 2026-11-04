<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    /** Values of `identity`: what the visitor typed into the ask. */
    const IDENTITY_NONE  = 0;
    const IDENTITY_EMAIL = 1;
    const IDENTITY_ORDER = 2;

    protected $table = 'wordpresschat_sessions';

    protected $guarded = ['id'];

    protected $dates = ['account_until', 'last_seen_at', 'created_at', 'updated_at'];

    /**
     * Whether the session holds a WordPress account login that is still
     * valid: a user id from a login token and an expiry in the future.
     */
    public function accountFresh(): bool
    {
        return (int) $this->wp_user_id > 0
            && $this->account_until !== null
            && $this->account_until->gt(now());
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }
}
