<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Modules\WordPressChat\Entities\Site;

class ResolveSite
{
    public function handle($request, Closure $next)
    {
        $route = $request->route();
        $publicId = $route ? $route->parameter('site') : null;

        // A lookup that fails (the sites table is missing on a first install
        // whose migrations were skipped, or the database is down) answers
        // 503 maintenance, not 500. Without a site there is no origin, so
        // this 503 has no CORS headers. A pending later migration finds the
        // site here, and RequireSchema answers its 503 with them. No
        // transaction is open here, so catching is safe on PostgreSQL.
        try {
            $site = $publicId ? Site::where('public_id', $publicId)->first() : null;
        } catch (QueryException $e) {
            \Log::warning('WordPressChat: the website could not be looked up, so the chat answers 503 maintenance: '.$e->getMessage());

            return RequireSchema::maintenance();
        }

        if (!$site) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $request->attributes->set('wordpresschat.site', $site);

        return $next($request);
    }
}
