<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * A consumed identity token id (jti). See Services\IdentityToken::open().
 */
class TokenUse extends Model
{
    protected $table = 'wordpresschat_token_uses';

    protected $guarded = ['id'];

    protected $dates = ['expires_at', 'created_at', 'updated_at'];
}
