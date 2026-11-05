<?php

namespace Modules\WordPressChat\Services;

/**
 * Has every migration shipped with the module run? Production installs often
 * skip `module:migrate` (core skips module migrations silently when Modules
 * is a symlink), and new code then reads columns and tables that do not
 * exist yet. The visitor API answers 503 and the admin pages show a notice
 * until an admin runs System -> Tools -> Migrate DB.
 *
 * Used by RequireSchema and the admin views only, never by listeners.
 */
class SchemaGuard
{
    /** Basenames (without .php) of the module's migrations, sorted. */
    public static function expected(): array
    {
        $names = [];
        foreach (glob(__DIR__.'/../Database/Migrations/*.php') ?: [] as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);

        return $names;
    }

    public static function cacheKey(): string
    {
        return 'wordpresschat.schema.'.md5(implode(',', self::expected()));
    }

    /** The shipped migrations that the migrations table does not list. */
    public static function pending(): array
    {
        $expected = self::expected();
        if (!$expected) {
            return [];
        }

        $done = [];
        foreach (array_chunk($expected, 500) as $chunk) {
            foreach (\DB::table('migrations')->whereIn('migration', $chunk)->pluck('migration') as $name) {
                $done[$name] = true;
            }
        }

        return array_values(array_filter($expected, function ($name) use ($done) {
            return !isset($done[$name]);
        }));
    }

    /**
     * True when nothing is pending. Only "true" is cached (forever): the
     * key names the migration set, so a module update with a new migration
     * asks again. A pending verdict is looked up on every call.
     */
    public static function ok(): bool
    {
        $key = self::cacheKey();

        try {
            if (\Cache::get($key) === true) {
                return true;
            }
        } catch (\Throwable $e) {
            // Cache down: ask the database.
        }

        if (self::pending()) {
            return false;
        }

        try {
            \Cache::forever($key, true);
        } catch (\Throwable $e) {
            // Do nothing.
        }

        return true;
    }
}
