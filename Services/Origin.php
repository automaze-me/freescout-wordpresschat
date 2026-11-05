<?php

namespace Modules\WordPressChat\Services;

class Origin
{
    /**
     * Reduce a URL to its origin (scheme://host[:port]): lowercase scheme and
     * host (IDN hosts as punycode), default ports dropped, path/query/fragment dropped. Returns null
     * for anything that is not http(s) with a host.
     */
    public static function normalize($url)
    {
        $parts = parse_url(trim((string) $url));

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);

        // An IDN host is compared in its punycode form, which is what a
        // browser sends in the Origin header. Without intl it can't be
        // converted, so it is refused rather than compared wrongly.
        if (preg_match('/[^\x00-\x7F]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii) || $ascii === '') {
                return null;
            }

            $host = $ascii;
        }

        $origin = $scheme.'://'.$host;

        if (!empty($parts['port'])) {
            $port = (int) $parts['port'];
            if (!(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
                $origin .= ':'.$port;
            }
        }

        return $origin;
    }
}
