<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Contracts\Encryption\DecryptException;
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

    /**
     * The secret, or null when it can't be decrypted, typically because the
     * FreeScout APP_KEY changed since it was stored. Logs a warning (never
     * the secret itself) so the admin learns to regenerate it.
     */
    public function secretOrNull(): ?string
    {
        try {
            $secret = is_string($this->secret) && $this->secret !== '' ? decrypt($this->secret) : null;
        } catch (DecryptException $e) {
            $secret = null;
        }

        if (!is_string($secret) || $secret === '') {
            \Log::warning('WordPressChat: the secret of website #'.$this->id.' cannot be read (the app key changed?). Regenerate it.');

            return null;
        }

        return $secret;
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
