<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Modules\WordPressChat\Services\SchemaGuard;

/**
 * Answers 503 while a module migration is pending. Sits after Cors (so the
 * 503 carries the CORS headers) and before Throttle (which writes to the
 * newest table, and must never run while that one may be missing). Only a
 * missing sites table (a first install whose migrations were skipped) is
 * answered earlier, by ResolveSite, without CORS headers.
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
