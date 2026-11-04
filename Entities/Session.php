<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    protected $table = 'wordpresschat_sessions';

    protected $guarded = ['id'];

    protected $dates = ['created_at', 'updated_at'];

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }
}
