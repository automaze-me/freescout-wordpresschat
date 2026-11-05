<?php

namespace Modules\WordPressChat\Console;

use Illuminate\Console\Command;
use Modules\WordPressChat\Services\RateLimiter;

/**
 * Housekeeping: scheduled hourly through the `schedule` Eventy filter.
 */
class Cleanup extends Command
{
    protected $signature = 'wordpresschat:cleanup';

    protected $description = 'Delete expired website chat data, such as old rate-limit counters';

    public function handle()
    {
        $this->line('Rate-limit counters deleted: '.RateLimiter::purge());
    }
}
