<?php

namespace Modules\WordPressChat\Services;

use App\Attachment;
use App\Thread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\WordPressChat\Entities\Site;

/**
 * Files in website chats (spec §5.3, Rulings E2, E3).
 *
 * A visitor's file is untrusted: its type is decided by finfo on its bytes
 * alone (the client's type and file name say nothing), only the five TYPES
 * pass, and it is stored under a cleaned name whose extension is the one of
 * the sniffed type. Agents may attach anything; what leaves for a browser
 * is described by servedMime(), contentDisposition() and displayName().
 */
class ChatAttachment
{
    /** The sniffed types a visitor may send => the extension stored with it. */
    const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Types a browser may show in place (Content-Disposition: inline). */
    const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** What every other file is served as. */
    const OPAQUE_TYPE = 'application/octet-stream';

    /** A site without a limit of its own (the column's default). */
    const DEFAULT_LIMIT_MB = 10;

    const MB = 1048576;

    /** The stored name's maximum, in bytes (so also in characters). */
    const MAX_NAME_BYTES = 255;

    /**
     * The largest file in MB a visitor may send to $site: the site's
     * max_attachment_mb, capped by PHP's upload_max_filesize and
     * post_max_size, rounded down, at least 1.
     */
    public static function effectiveLimitMb(Site $site): int
    {
        return self::limitFor(
            (int) $site->max_attachment_mb,
            (string) ini_get('upload_max_filesize'),
            (string) ini_get('post_max_size')
        );
    }

    /**
     * effectiveLimitMb() for given ini values. An ini value of 0 or empty
     * caps nothing (post_max_size = 0 means "no limit" to PHP).
     */
    public static function limitFor(int $siteMb, string $uploadMaxFilesize, string $postMaxSize): int
    {
        $bytes = ($siteMb > 0 ? $siteMb : self::DEFAULT_LIMIT_MB) * self::MB;

        foreach ([$uploadMaxFilesize, $postMaxSize] as $ini) {
            $cap = self::iniBytes($ini);
            if ($cap > 0) {
                $bytes = min($bytes, $cap);
            }
        }

        return max(1, intdiv($bytes, self::MB));
    }

    /**
     * Bytes of a php.ini size value ("8M", "512K", "1G", "2097152"); 0 for
     * an empty or zero value.
     */
    public static function iniBytes(string $value): int
    {
        $value = strtolower(trim($value));

        if ($value === '' || !preg_match('/\A(\d+)\s*([kmg]?)/', $value, $m)) {
            return 0;
        }

        $bytes = (int) $m[1];

        switch ($m[2]) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
        }

        return (int) $bytes;
    }

    /**
     * Null when $file may be stored for $site, else why not: `missing`
     * (no usable upload: PHP dropped it, it is empty, or it is no upload at
     * all), `too_large`, or `type`. PHP's own size errors count as
     * too_large. Decided on the file's bytes, never on the client's type or
     * name. Writes nothing.
     */
    public static function validate(?UploadedFile $file, Site $site): ?string
    {
        if (!$file) {
            return 'missing';
        }

        $error = $file->getError();

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'too_large';
        }

        // isValid() also requires is_uploaded_file() for a real request, so
        // no path but PHP's own temp file is ever read.
        if ($error !== UPLOAD_ERR_OK || !$file->isValid()) {
            return 'missing';
        }

        $path = self::path($file);
        $size = $path === null ? false : @filesize($path);

        if ($size === false || $size < 1) {
            return 'missing';
        }

        if ($size > self::effectiveLimitMb($site) * self::MB) {
            return 'too_large';
        }

        return self::sniff($path) === null ? 'type' : null;
    }

    /**
     * Stores a file validate() passed on $thread: Attachment::create() with
     * the cleaned name and the sniffed type, then has_attachments. Refuses
     * a file whose bytes are not one of the TYPES.
     */
    public static function store(UploadedFile $file, Thread $thread): Attachment
    {
        $path = self::path($file);
        $mime = $path === null ? null : self::sniff($path);

        if ($mime === null) {
            throw new \InvalidArgumentException('WordPressChat: refusing to store a file that is not an allowed type.');
        }

        // Default upload mode: the customer mode renames by core's own
        // allow-lists (which lack image/png); the type is settled already.
        $attachment = Attachment::create(
            self::cleanName((string) $file->getClientOriginalName(), self::TYPES[$mime]),
            $mime,
            null,
            null,
            $file,
            false,
            $thread->id,
            null
        );

        if (!$attachment) {
            throw new \RuntimeException('WordPressChat: the attachment of thread #'.$thread->id.' could not be stored.');
        }

        $thread->has_attachments = true;
        $thread->save();

        return $attachment;
    }

    /**
     * The name store() gives $file (before core's own replacements, such as
     * spaces to underscores), or null when its bytes are not an allowed type.
     */
    public static function fileName(UploadedFile $file): ?string
    {
        $path = self::path($file);
        $mime = $path === null ? null : self::sniff($path);

        return $mime === null ? null : self::cleanName((string) $file->getClientOriginalName(), self::TYPES[$mime]);
    }

    /**
     * The allowed type finfo reads from the file's bytes, or null.
     */
    public static function sniff(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = @$finfo->file($path);
        $mime = is_string($mime) ? strtolower(trim($mime)) : '';

        return isset(self::TYPES[$mime]) ? $mime : null;
    }

    /**
     * The type a browser receives for $attachment: its stored type when
     * that is one of the TYPES, otherwise application/octet-stream. An
     * agent's HTML or SVG file must never become a text/html or SVG blob in
     * the widget, where it would run in the shop's own origin.
     */
    public static function servedMime(Attachment $attachment): string
    {
        $mime = strtolower(trim((string) $attachment->mime_type));

        return isset(self::TYPES[$mime]) ? $mime : self::OPAQUE_TYPE;
    }

    /**
     * The file name a browser receives: the stored name without control,
     * format or bidi characters, path parts, or characters a header or a
     * file system can't hold.
     */
    public static function displayName(Attachment $attachment): string
    {
        $name = trim(self::stripName((string) $attachment->file_name));

        return $name !== '' ? $name : 'file';
    }

    /**
     * `inline` for the four image types, `attachment` for everything else,
     * with an ASCII fallback name and the full name in RFC 5987 form.
     */
    public static function contentDisposition(Attachment $attachment): string
    {
        $type = in_array(self::servedMime($attachment), self::INLINE_TYPES, true) ? 'inline' : 'attachment';
        $name = self::displayName($attachment);

        // Only characters that need no quoting rules in a quoted-string.
        $ascii = trim(preg_replace('/[^A-Za-z0-9._ ()+\-]+/', '_', Str::ascii($name)));
        if ($ascii === '' || trim($ascii, '._ ') === '') {
            $ascii = 'file';
        }

        $header = $type.'; filename="'.$ascii.'"';

        if ($ascii !== $name) {
            $header .= "; filename*=UTF-8''".rawurlencode($name);
        }

        return $header;
    }

    /**
     * The visitor's file name made safe to store: the last path segment,
     * control, format and bidi characters removed, characters a file system
     * or header can't hold replaced, the client's extension dropped and any
     * other dot turned into an underscore (so no "shell.php.png"), then the
     * sniffed type's extension. Falls back to "file". At most 255 bytes.
     */
    private static function cleanName(string $name, string $extension): string
    {
        $base = self::stripName($name);

        $dot = strrpos($base, '.');
        if ($dot !== false) {
            $base = substr($base, 0, $dot);
        }

        $base = trim(str_replace('.', '_', $base));
        if ($base === '') {
            $base = 'file';
        }

        // mb_strcut() cuts on a byte length without splitting a character.
        $base = mb_strcut($base, 0, self::MAX_NAME_BYTES - 1 - strlen($extension), 'UTF-8');

        return $base.'.'.$extension;
    }

    private static function stripName(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return '';
        }

        $name = preg_replace('#\A.*[/\\\\]#s', '', $name);
        // \p{C}: control (incl. NUL and DEL), format (bidi overrides),
        // private-use, surrogate and unassigned code points.
        $name = preg_replace('/\p{C}+/u', '', $name);

        return (string) preg_replace('/[<>:"|?*;]+/', '_', (string) $name);
    }

    /** The upload's readable temp path, or null. */
    private static function path(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        return is_string($path) && $path !== '' && is_file($path) && is_readable($path) ? $path : null;
    }
}
