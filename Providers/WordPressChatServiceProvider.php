<?php

namespace Modules\WordPressChat\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\WordPressChat\Console\SendDueEmails;
use Modules\WordPressChat\Listeners\PublishReplyToChat;
use Modules\WordPressChat\Listeners\RecordCustomerEmail;
use Modules\WordPressChat\Services\ConversationHeader;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\WordPressChat;

class WordPressChatServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'wordpresschat');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'wordpresschat');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // FreeScout's module install/update flow never restarts queue
        // workers, so a long-running worker keeps executing the module code
        // it booted with. Must never break boot: this also runs for artisan
        // commands on half-installed systems.
        try {
            self::restartQueueIfVersionChanged();
        } catch (\Throwable $e) {
            // Do nothing.
        }

        $this->hooks();
    }

    /**
     * Broadcast queue:restart once whenever the installed module version
     * changes. The version is read from module.json ON DISK, so even a worker
     * running stale in-memory code detects the change and retires itself.
     */
    public static function restartQueueIfVersionChanged()
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../module.json'), true);
        $version = isset($manifest['version']) ? $manifest['version'] : null;

        // Read without Option::$cache: core's Option::set() never updates
        // that static, and get() caches misses as a sentinel, so a cached
        // read could compare against a stale value forever.
        if (!$version || \Option::get('wordpresschat.version', false, true, false) === $version) {
            return false;
        }

        \Artisan::call('queue:restart');
        \Option::set('wordpresschat.version', $version);
        unset(\Option::$cache['wordpresschat.version']);

        return true;
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'wordpresschat');

        $this->commands([
            SendDueEmails::class,
        ]);
    }

    protected function hooks()
    {
        // The fallback timer (Ruling D1). Core applies this filter to its
        // scheduler, which FreeScout's per-minute cron runs.
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('wordpresschat:send-due-emails')
                ->everyMinute()
                ->withoutOverlapping();

            return $schedule;
        });

        // Chat emails (Plan 3): every due reply, and the continue link.
        \Eventy::addFilter('email.reply_to_customer.threads', function ($threads, $conversation = null, $mailbox = null) {
            return \Modules\WordPressChat\Services\ChatEmail::threads($threads, $conversation);
        }, 20, 3);

        \Eventy::addAction('reply_email.before_signature', function ($thread, $loop, $threads, $conversation = null) {
            \Modules\WordPressChat\Services\ChatEmail::echoLink($loop, $conversation);
        }, 20, 6);

        \Eventy::addFilter('email.reply_to_customer.template_name_text', function ($name) {
            return \Modules\WordPressChat\Services\ChatEmail::textTemplate($name);
        }, 20, 1);

        // Manage -> Website Chat (admins only).
        \Eventy::addAction('menu.manage.append', function () {
            if (auth()->user() && auth()->user()->isAdmin()) {
                echo view('wordpresschat::menu')->render();
            }
        });

        // Make the website chat selectable as a communication channel.
        \Eventy::addFilter('channels.list', function ($channels) {
            $channels[WordPressChat::CHANNEL] = WordPressChat::channelName();

            return $channels;
        }, 20, 1);

        // Channel code -> display name (used by CustomerChannel::getChannelName()).
        \Eventy::addFilter('channel.name', function ($name, $channel = null) {
            if ((int) $channel === WordPressChat::CHANNEL) {
                return WordPressChat::channelName();
            }

            return $name;
        }, 20, 2);

        // After core's undo window, mark the newest agent reply as published
        // so the widget's poll may show it.
        \Eventy::addAction('chat_conversation.send_reply', [new PublishReplyToChat(), 'handle'], 20, 3);

        // A customer's email in a chat conversation cuts off unverified browsers.
        \Eventy::addAction('conversation.customer_replied', [new RecordCustomerEmail(), 'handle'], 20, 3);

        // Pill, identity badge and order-only warning. In chat mode core hides
        // after_subject_block in a collapsed accordion, so chat mode renders
        // through after_subject; each hook checks the mode itself, so exactly
        // one of them echoes.
        \Eventy::addAction('conversation.after_subject', function ($conversation, $mailbox) {
            if ($conversation->isInChatMode()) {
                echo ConversationHeader::render($conversation);
            }
        }, 20, 2);

        \Eventy::addAction('conversation.after_subject_block', function ($conversation, $mailbox) {
            if (!$conversation->isInChatMode()) {
                echo ConversationHeader::render($conversation);
            }
        }, 20, 2);

        // "via email" chip next to the sender's name.
        \Eventy::addAction('thread.after_person_action', function ($thread) {
            echo ConversationHeader::threadChip($thread);
        }, 20, 1);

        // Core renders a line item only through getActionText(), which has no
        // branch for our NULL action_type and falls through to this filter.
        // The body is stored escaped; a caller that asks for unescaped text
        // (getActionDescription(), which escapes the result itself) gets it
        // decoded, or it would be escaped twice. A failure item is red for
        // escaped (HTML) callers only: core's thread view passes the result
        // through safe_raw_html(), which keeps a <span class>.
        \Eventy::addFilter('thread.action_text', function ($didThis, $thread, $conversationNumber = null, $escape = true, $viewedByUser = null) {
            if ((int) $thread->type === \App\Thread::TYPE_LINEITEM
                && $thread->getMeta(IdentityLineItem::LINEITEM_META)) {
                if (!$escape) {
                    return html_entity_decode($thread->body, ENT_QUOTES);
                }

                if ($thread->getMeta(FailureLineItem::FAILURE_META)) {
                    return '<span class="text-danger">'.$thread->body.'</span>';
                }

                return $thread->body;
            }

            return $didThis;
        }, 20, 5);
    }
}
