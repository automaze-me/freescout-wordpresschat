<?php

namespace Modules\WordPressChat\Services;

class Origin
{
    /**
     * Reduce a URL to its origin (scheme://host[:port]): lowercase scheme and
     * host, default ports dropped, path/query/fragment dropped. Returns null
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

        $origin = $scheme.'://'.strtolower($parts['host']);

        if (!empty($parts['port'])) {
            $port = (int) $parts['port'];
            if (!(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
                $origin .= ':'.$port;
            }
        }

        return $origin;
    }
}
