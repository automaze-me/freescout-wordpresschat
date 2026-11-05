<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\SchemaGuard;

class ResolveSite
{
    public function handle($request, Closure $next)
    {
        // While a module migration is pending the sites table may not exist
        // yet (a first install whose migrations were skipped), so the
        // lookup would be a 500. This runs before Cors, and without a site
        // there is no origin to answer with: this 503 has no CORS headers.
        if (!SchemaGuard::ok()) {
            return RequireSchema::maintenance();
        }

        $route = $request->route();
        $publicId = $route ? $route->parameter('site') : null;

        $site = $publicId ? Site::where('public_id', $publicId)->first() : null;

        if (!$site) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $request->attributes->set('wordpresschat.site', $site);

        return $next($request);
    }
}
