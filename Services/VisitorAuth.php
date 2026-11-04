<?php

namespace Modules\WordPressChat\Services;

use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;

class VisitorAuth
{
    /**
     * Create a visitor session. The plain token is returned once and only its
     * SHA-256 hash is stored.
     */
    public static function issue(Site $site): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $session = Session::create([
            'site_id'    => $site->id,
            'public_id'  => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $token),
        ]);

        return ['session' => $session, 'token' => $token];
    }

    public static function resolve(Site $site, $token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        return Session::where('site_id', $site->id)
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }
}
