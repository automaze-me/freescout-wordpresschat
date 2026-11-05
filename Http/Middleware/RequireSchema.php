<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Modules\WordPressChat\Services\SchemaGuard;

/**
 * Answers 503 while a module migration is pending. ResolveSite already
 * answers the same before it reads the sites table; this one stays in
 * front of Throttle (which writes to the newest table, and must never run
 * while that one may be missing) should the middleware order ever change.
 * The verdict is cached once it is "ok", so the second check costs a cache
 * read.
 */
class RequireSchema
{
    public function handle($request, Closure $next)
    {
        if (!SchemaGuard::ok()) {
            return self::maintenance();
        }

        return $next($request);
    }

    /** The maintenance answer: 503 {"error":"maintenance"}, Retry-After: 300. */
    public static function maintenance()
    {
        return response()->json(['error' => 'maintenance'], 503)->header('Retry-After', '300');
    }
}
