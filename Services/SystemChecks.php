<?php

namespace Modules\WordPressChat\Services;

use Modules\WordPressChat\Entities\Site;

/**
 * The production checks software can see, for System -> Status.
 * Each row: label, ok (true, false, or null when only informative), detail.
 */
class SystemChecks
{
    /** A valid 1x1 WebP image. */
    const WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=';

    public static function rows(): array
    {
        return [
            self::database(),
            self::pcntl(),
            self::webp(),
            self::uploadLimits(),
            self::clientAddress(),
        ];
    }

    /**
     * The address FreeScout sees for the admin's own request (see
     * clientAddressRow()).
     */
    protected static function clientAddress(): array
    {
        $request = request();

        return self::clientAddressRow(
            (string) $request->ip(),
            $request->headers->get('X-Forwarded-For'),
            (string) $request->server('REMOTE_ADDR')
        );
    }

    /**
     * The per-IP abuse limits only work when FreeScout sees each visitor's
     * own address. $ip is what FreeScout sees ($request->ip()), $remoteAddr
     * the connection's address. Not ok when:
     * - the request carries an X-Forwarded-For header and $ip is still the
     *   connection's address: a proxy whose header FreeScout ignores
     *   (APP_TRUSTED_PROXIES doesn't name it), so every visitor would share
     *   the proxy's address;
     * - $ip is not a public address (private, shared, loopback, link-local,
     *   unique local, also as IPv4-mapped IPv6): a proxy or a container's
     *   port forwarding hides the visitors, unless the admin opened
     *   FreeScout from inside the same network.
     */
    public static function clientAddressRow(string $ip, ?string $forwardedFor, string $remoteAddr): array
    {
        $label = __('Client address');

        if ($forwardedFor !== null && trim($forwardedFor) !== '' && $ip === $remoteAddr) {
            return ['label' => $label, 'ok' => false, 'detail' => __('FreeScout sees this request as coming from :ip and ignores its X-Forwarded-For header, so a proxy in front of FreeScout is not trusted. All visitors then share the proxy\'s address and its per-IP limits. Set APP_TRUSTED_PROXIES to the proxy\'s address (never * when FreeScout can also be reached directly).', ['ip' => $ip])];
        }

        if (!self::isPublicAddress($ip)) {
            return ['label' => $label, 'ok' => false, 'detail' => __('FreeScout sees this request as coming from :ip, which is not a public address. That is expected only if you opened FreeScout from inside its own network. Otherwise a proxy or a container\'s port forwarding hides the visitors\' addresses, and all visitors share the per-IP limits: behind a proxy, set APP_TRUSTED_PROXIES to its address.', ['ip' => $ip === '' ? __('nothing') : $ip])];
        }

        return ['label' => $label, 'ok' => true, 'detail' => __('FreeScout sees this request as coming from :ip, a public address. The per-IP limits count each visitor on their own.', ['ip' => $ip])];
    }

    /**
     * Whether $ip is a public unicast address. Not public: anything that
     * isn't a plain IPv4 or IPv6 address (a zone id included), 0.0.0.0/8,
     * 10/8, 100.64/10 (shared, CGNAT), 127/8, 169.254/16, 172.16/12,
     * 192.168/16, ::, ::1, fc00::/7 (unique local), fe80::/10 (link-local),
     * and IPv4-mapped IPv6 (::ffff:a.b.c.d) as its IPv4 address.
     */
    public static function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                $packed = substr($packed, 12);
            } else {
                $first = ord($packed[0]);
                $second = ord($packed[1]);

                return $packed !== str_repeat("\0", 16)
                    && $packed !== str_repeat("\0", 15)."\x01"
                    && ($first & 0xfe) !== 0xfc
                    && !($first === 0xfe && ($second & 0xc0) === 0x80);
            }
        }

        $a = ord($packed[0]);
        $b = ord($packed[1]);

        return !($a === 0
            || $a === 10
            || ($a === 100 && ($b & 0xc0) === 64)
            || $a === 127
            || ($a === 169 && $b === 254)
            || ($a === 172 && ($b & 0xf0) === 16)
            || ($a === 192 && $b === 168));
    }

    protected static function database(): array
    {
        try {
            $pending = SchemaGuard::pending();
        } catch (\Throwable $e) {
            return ['label' => __('Database'), 'ok' => false, 'detail' => __('The database could not be checked: :error', ['error' => $e->getMessage()])];
        }

        if ($pending) {
            return ['label' => __('Database'), 'ok' => false, 'detail' => __('Pending migrations: :names. Open System → Tools and click Migrate DB.', ['names' => implode(', ', $pending)])];
        }

        return ['label' => __('Database'), 'ok' => true, 'detail' => __('All migrations have run.')];
    }

    protected static function pcntl(): array
    {
        if (function_exists('pcntl_signal')) {
            return ['label' => __('pcntl'), 'ok' => true, 'detail' => __('Available. Queue workers can shut down cleanly.')];
        }

        return ['label' => __('pcntl'), 'ok' => false, 'detail' => __('The PHP pcntl extension is missing. Queue workers cannot stop gracefully, so restarts may interrupt a job.')];
    }

    protected static function webp(): array
    {
        $mime = null;
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer(base64_decode(self::WEBP));
        }

        if ($mime === 'image/webp') {
            return ['label' => __('WebP detection'), 'ok' => true, 'detail' => __('WebP images are recognized, so visitors can send them.')];
        }

        return ['label' => __('WebP detection'), 'ok' => false, 'detail' => __('This server does not recognize WebP images (finfo answered :mime). Visitors cannot attach them.', ['mime' => $mime ?: __('nothing')])];
    }

    protected static function uploadLimits(): array
    {
        $effective = null;
        $configured = null;
        try {
            $site = Site::orderBy('max_attachment_mb', 'desc')->first();
            if ($site) {
                $configured = (int) $site->max_attachment_mb;
                $effective = ChatAttachment::effectiveLimitMb($site);
            }
        } catch (\Throwable $e) {
            // Table missing: the Database row reports it.
        }

        $ini = ini_get('upload_max_filesize').' / '.ini_get('post_max_size');

        if ($effective === null) {
            return ['label' => __('Upload limits'), 'ok' => null, 'detail' => __('No website yet. PHP upload_max_filesize / post_max_size: :ini', ['ini' => $ini])];
        }

        if ($effective < $configured) {
            return ['label' => __('Upload limits'), 'ok' => false, 'detail' => __('Visitors can send :effective MB, but a website allows :configured MB. PHP limits it (upload_max_filesize / post_max_size: :ini).', ['effective' => $effective, 'configured' => $configured, 'ini' => $ini])];
        }

        return ['label' => __('Upload limits'), 'ok' => true, 'detail' => __('Visitors can send :effective MB (PHP upload_max_filesize / post_max_size: :ini).', ['effective' => $effective, 'ini' => $ini])];
    }
}
