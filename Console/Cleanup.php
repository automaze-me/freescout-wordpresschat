<?php

namespace Modules\WordPressChat\Console;

use Illuminate\Console\Command;
use Modules\WordPressChat\Services\RateLimiter;
use Modules\WordPressChat\Services\SchemaGuard;
use Modules\WordPressChat\Services\VisitorAuth;

/**
 * Housekeeping: scheduled hourly through the `schedule` Eventy filter.
 *
 * Does nothing while a module migration is pending (a table it purges may
 * not exist yet). Otherwise each purge runs on its own: one that fails is
 * logged and printed, the other still runs, and the command exits with 1.
 */
class Cleanup extends Command
{
    protected $signature = 'wordpresschat:cleanup';

    protected $description = 'Delete expired website chat data: old rate-limit counters and orphan visitor sessions';

    public function handle()
    {
        if (!SchemaGuard::ok()) {
            $this->line('Skipped: a Website Chat database migration is pending. Open System → Tools and click Migrate DB.');

            return 0;
        }

        $failed = false;

        foreach ([
            'Rate-limit counters deleted' => 'purgeRateLimits',
            'Orphan sessions deleted'     => 'purgeOrphanSessions',
        ] as $label => $method) {
            try {
                $this->line($label.': '.$this->$method());
            } catch (\Throwable $e) {
                $failed = true;
                \Log::error('WordPressChat: wordpresschat:cleanup could not run '.$method.'(): '.$e->getMessage());
                $this->error($label.': failed ('.$e->getMessage().')');
            }
        }

        return $failed ? 1 : 0;
    }

    protected function purgeRateLimits(): int
    {
        return RateLimiter::purge();
    }

    protected function purgeOrphanSessions(): int
    {
        return VisitorAuth::purgeOrphans();
    }
}
