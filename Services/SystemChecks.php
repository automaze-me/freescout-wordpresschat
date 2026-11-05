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
        ];
    }

    protected static function database(): array
    {
        try {
            $pending = SchemaGuard::pending();
        } catch (\Throwable $e) {
            return ['label' => 'Database', 'ok' => false, 'detail' => __('The database could not be checked: :error', ['error' => $e->getMessage()])];
        }

        if ($pending) {
            return ['label' => 'Database', 'ok' => false, 'detail' => __('Pending migrations: :names. Open System → Tools and click Migrate DB.', ['names' => implode(', ', $pending)])];
        }

        return ['label' => 'Database', 'ok' => true, 'detail' => __('All migrations have run.')];
    }

    protected static function pcntl(): array
    {
        if (function_exists('pcntl_signal')) {
            return ['label' => 'pcntl', 'ok' => true, 'detail' => __('Available. Queue workers can shut down cleanly.')];
        }

        return ['label' => 'pcntl', 'ok' => false, 'detail' => __('The PHP pcntl extension is missing. Queue workers cannot stop gracefully, so restarts may interrupt a job.')];
    }

    protected static function webp(): array
    {
        $mime = null;
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer(base64_decode(self::WEBP));
        }

        if ($mime === 'image/webp') {
            return ['label' => 'WebP detection', 'ok' => true, 'detail' => __('WebP images are recognized, so visitors can send them.')];
        }

        return ['label' => 'WebP detection', 'ok' => false, 'detail' => __('This server does not recognize WebP images (finfo answered :mime). Visitors cannot attach them.', ['mime' => $mime ?: 'nothing'])];
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
            return ['label' => 'Upload limits', 'ok' => null, 'detail' => __('No website yet. PHP upload_max_filesize / post_max_size: :ini', ['ini' => $ini])];
        }

        if ($effective < $configured) {
            return ['label' => 'Upload limits', 'ok' => false, 'detail' => __('Visitors can send :effective MB, but a website allows :configured MB. PHP limits it (upload_max_filesize / post_max_size: :ini).', ['effective' => $effective, 'configured' => $configured, 'ini' => $ini])];
        }

        return ['label' => 'Upload limits', 'ok' => true, 'detail' => __('Visitors can send :effective MB (PHP upload_max_filesize / post_max_size: :ini).', ['effective' => $effective, 'ini' => $ini])];
    }
}
