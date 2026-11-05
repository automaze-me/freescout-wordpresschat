<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Illuminate\Http\UploadedFile;
use Modules\WordPressChat\Services\RateLimiter;

/**
 * Rate limits on the public chat API (spec §10). Runs after Cors, so a 429
 * carries the CORS headers like every API answer, and before the
 * controller, so it counts every attempt, including the ones the
 * controller would refuse (401, 422 …). Polls (`wordpresschat.api.index`)
 * and preflights (Cors answers OPTIONS itself) are never counted.
 */
class Throttle
{
    /**
     * Per route name, the rules in order. Each counts against its `scope`,
     * keyed by the client's address (`ip`, see RateLimiter::ipKey()) or its
     * chat token (`token`, see tokenKey()), at most `max` per `window`
     * seconds. A rule with `header` applies only with (true) or without
     * (false) an X-Chat-Token header; one with `fields` only when the body
     * has at least one of them. A `bytes` rule counts the uploaded file's
     * size instead of 1.
     */
    const RULES = [
        'wordpresschat.api.send' => [
            ['scope' => 'chat',       'key' => 'ip',    'max' => 10,        'window' => 3600, 'header' => false],
            ['scope' => 'message',    'key' => 'token', 'max' => 20,        'window' => 60,   'header' => true],
            ['scope' => 'message-ip', 'key' => 'ip',    'max' => 60,        'window' => 60],
            ['scope' => 'upload',     'key' => 'token', 'max' => 52428800,  'window' => 3600, 'fields' => ['file'], 'bytes' => true],
            ['scope' => 'upload-ip',  'key' => 'ip',    'max' => 209715200, 'window' => 3600, 'fields' => ['file'], 'bytes' => true],
        ],
        'wordpresschat.api.identity' => [
            ['scope' => 'identity',    'key' => 'token', 'max' => 5,  'window' => 900, 'fields' => ['email', 'order_token']],
            ['scope' => 'identity-ip', 'key' => 'ip',    'max' => 10, 'window' => 900, 'fields' => ['email', 'order_token']],
            ['scope' => 'login-ip',    'key' => 'ip',    'max' => 60, 'window' => 900, 'fields' => ['login_token']],
        ],
        'wordpresschat.api.continue' => [
            ['scope' => 'continue-ip', 'key' => 'ip', 'max' => 10, 'window' => 900],
        ],
        'wordpresschat.api.seen' => [
            ['scope' => 'seen', 'key' => 'token', 'max' => 60, 'window' => 60],
        ],
        'wordpresschat.api.attachment' => [
            ['scope' => 'download', 'key' => 'token', 'max' => 120, 'window' => 60],
        ],
        'wordpresschat.api.logout' => [
            ['scope' => 'logout-ip', 'key' => 'ip', 'max' => 30, 'window' => 60],
        ],
        'wordpresschat.api.ping' => [
            ['scope' => 'ping-ip', 'key' => 'ip', 'max' => 10, 'window' => 60],
        ],
    ];

    /**
     * Applies every matching rule, so each one counts the attempt even when
     * an earlier one already refused it; the first refusal answers 429.
     */
    public function handle($request, Closure $next)
    {
        $route = $request->route();
        $name = $route ? $route->getName() : null;
        $rules = self::RULES;

        if ($name === null || !isset($rules[$name])) {
            return $next($request);
        }

        $retryAfter = null;

        foreach ($rules[$name] as $rule) {
            if (!self::applies($rule, $request)) {
                continue;
            }

            $key = $rule['key'] === 'ip'
                ? RateLimiter::ipKey((string) $request->ip())
                : self::tokenKey($request);

            if ($key === null) {
                continue;
            }

            $amount = empty($rule['bytes']) ? 1 : self::fileBytes($request);
            $retry = RateLimiter::hit($rule['scope'], $key, $rule['max'], $rule['window'], $amount);

            if ($retry !== null && $retryAfter === null) {
                $retryAfter = $retry;
            }
        }

        if ($retryAfter !== null) {
            return response()
                ->json(['error' => 'rate_limited', 'retry_after' => $retryAfter], 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        return $next($request);
    }

    private static function applies(array $rule, $request): bool
    {
        if (isset($rule['header']) && $rule['header'] !== self::hasChatToken($request)) {
            return false;
        }

        if (!isset($rule['fields'])) {
            return true;
        }

        // A key counts as given even when empty, as in the controllers.
        foreach ($rule['fields'] as $field) {
            if ($request->has($field)) {
                return true;
            }
        }

        return false;
    }

    /** An empty header is no header, as in the controllers. */
    private static function hasChatToken($request): bool
    {
        $token = $request->header('X-Chat-Token');

        return is_string($token) && $token !== '';
    }

    /**
     * The SHA-256 of the X-Chat-Token header; without one, of `new_token`
     * when that is a string (the widget's first message). Null: no
     * per-token rule applies.
     */
    private static function tokenKey($request): ?string
    {
        if (self::hasChatToken($request)) {
            return hash('sha256', $request->header('X-Chat-Token'));
        }

        $newToken = $request->input('new_token');

        return is_string($newToken) ? hash('sha256', $newToken) : null;
    }

    /**
     * The size of the `file` upload, before any check of it; 0 when `file`
     * is no single uploaded file, or one PHP dropped (no temp file to
     * measure, nothing kept).
     */
    private static function fileBytes($request): int
    {
        $file = $request->file('file');

        if (!$file instanceof UploadedFile) {
            return 0;
        }

        try {
            return max(0, (int) $file->getSize());
        } catch (\RuntimeException $e) {
            return 0;
        }
    }
}
