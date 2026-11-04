<?php

namespace Modules\WordPressChat\Console;

use Illuminate\Console\Command;
use Modules\WordPressChat\Services\DueEmails;

/**
 * The fallback timer (Ruling D1): scheduled every minute through the
 * `schedule` Eventy filter.
 */
class SendDueEmails extends Command
{
    protected $signature = 'wordpresschat:send-due-emails';

    protected $description = 'Queue fallback emails for website chat replies the visitor has not seen';

    public function handle()
    {
        $this->line('Batches queued: '.DueEmails::sweep());
    }
}
