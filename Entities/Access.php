<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

class Access extends Model
{
    protected $table = 'wordpresschat_access';

    protected $guarded = ['id'];

    protected $dates = ['verified_at', 'created_at', 'updated_at'];
}
