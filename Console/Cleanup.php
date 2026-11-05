<?php

namespace Modules\WordPressChat\Console;

use Illuminate\Console\Command;
use Modules\WordPressChat\Services\RateLimiter;
use Modules\WordPressChat\Services\VisitorAuth;

/**
 * Housekeeping: scheduled hourly through the `schedule` Eventy filter.
 */
class Cleanup extends Command
{
    protected $signature = 'wordpresschat:cleanup';

    protected $description = 'Delete expired website chat data: old rate-limit counters and orphan visitor sessions';

    public function handle()
    {
        $this->line('Rate-limit counters deleted: '.RateLimiter::purge());
        $this->line('Orphan sessions deleted: '.VisitorAuth::purgeOrphans());
    }
}
