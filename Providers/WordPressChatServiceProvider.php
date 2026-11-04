<?php

namespace Modules\WordPressChat\Providers;

use Illuminate\Support\ServiceProvider;
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
    }

    protected function hooks()
    {
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
    }
}
