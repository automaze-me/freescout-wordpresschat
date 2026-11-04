<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * A continue link (spec §6.2). See Services\ContinueLink.
 */
class Link extends Model
{
    protected $table = 'wordpresschat_links';

    protected $guarded = ['id'];

    protected $dates = ['expires_at', 'used_at', 'created_at', 'updated_at'];
}
