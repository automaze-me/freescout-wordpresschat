<?php

namespace Modules\WordPressChat\Entities;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Site extends Model
{
    /** Sessions deleted per transaction by purgeVisitorData(). */
    const PURGE_BATCH = 500;

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

    /**
     * Deletes what this site's visitors left in the module before the site
     * itself is deleted: their sessions, the sessions' Access rows and the
     * site's continue links. Message rows lose their session_id. The
     * conversations, their states and message rows stay, so agents keep the
     * history; the states' site_id becomes 0 ("no site", see
     * ConversationState::of()). Otherwise a site that later gets the same
     * id (MySQL <= 5.7 and MariaDB < 10.2.4 reset AUTO_INCREMENT on a
     * restart) would find the deleted site's account chats.
     *
     * Identity token uses (jti rows) carry no site, so they cannot be told
     * apart; they hold no visitor data and IdentityToken purges them a day
     * after they expire.
     *
     * Sessions go in batches, each in a transaction whose first statement
     * locks the batch's session rows (Ruling C10): a request that is
     * writing for one of them commits first, and one that comes later finds
     * its row gone and answers 401 (SessionGoneException).
     */
    public function purgeVisitorData(): void
    {
        $siteId = (int) $this->id;

        do {
            $count = DB::transaction(function () use ($siteId) {
                $ids = Session::where('site_id', $siteId)
                    ->orderBy('id')
                    ->limit(self::PURGE_BATCH)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                if ($ids) {
                    // toBase(): no updated_at, so no row looks touched.
                    Message::whereIn('session_id', $ids)->toBase()->update(['session_id' => null]);
                    Access::whereIn('session_id', $ids)->delete();
                    Session::whereIn('id', $ids)->delete();
                }

                return count($ids);
            });
        } while ($count > 0);

        Link::where('site_id', $siteId)->delete();

        // toBase(): no updated_at, so no state looks touched.
        ConversationState::where('site_id', $siteId)->toBase()->update(['site_id' => 0]);
    }

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }
}
