<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve the shop's logo — and its A5 invoice paper and invoice font, which are
 * kept the same way.
 *
 * Section 8c: "store logos as files with only the path in settings — never
 * base64 in the database." The path was being turned into a /storage/… URL,
 * which only resolves once `php artisan storage:link` has created the symlink —
 * and on Windows that needs administrator rights, so on a normal XAMPP install
 * it silently does not exist and every logo is a broken image.
 *
 * Reading the file and sending it works everywhere, with no setup step to
 * forget. The route is deliberately outside the auth middleware, because the
 * login page shows the logo before anyone has signed in.
 */
class BrandingController extends Controller
{
    /** The setting that holds the A5 invoice paper's path. */
    public const PAPER = 'invoice_a5_paper';

    /** The setting that holds the invoice font's path. */
    public const FONT = 'invoice_font';

    /** What each font file is sent as, by the name it was kept under. */
    private const FONT_TYPES = ['ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];

    public function logo(): Response
    {
        return self::send(self::path(), private: false);
    }

    /**
     * The shop's own blank A5 invoice — PROJECT_DOC Section 9, Soran,
     * 2026-10-20: *"please use this without change any thing"*. Only the
     * government invoice prints it, so unlike the logo it is behind the login.
     */
    public function paper(): Response
    {
        return self::send(self::path(self::PAPER), private: true);
    }

    /**
     * The font the A5 invoice prints in — the invoice designer, Soran,
     * 2026-10-20: *"i want use specific font"*. Behind the login with the paper.
     */
    public function font(): Response
    {
        $path = self::path(self::FONT);

        return self::send($path, private: true, type: $path === null
            ? null
            : self::FONT_TYPES[strtolower(pathinfo($path[1], PATHINFO_EXTENSION))] ?? null);
    }

    /**
     * @param  array{0: string, 1: string}|null  $path
     * @param  bool  $private  for what only a signed-in reader sees
     * @param  string|null  $type  when guessing from the contents would be wrong
     */
    private static function send(?array $path, bool $private, ?string $type = null): Response
    {
        abort_if($path === null, 404);

        [$disk, $file] = $path;

        $response = response()->file(Storage::disk($disk)->path($file), array_filter([
            // The URL carries a hash of the stored path, so a new file is a new
            // URL and this can be cached hard.
            'Cache-Control' => 'max-age=31536000, immutable',
            'Content-Type' => $type,
        ]));

        // ⚠️ A file response marks itself public whatever the header says, so
        // the paper's has to be told otherwise.
        $private ? $response->setPrivate() : $response->setPublic();

        return $response;
    }

    /**
     * Where the logo — or the paper — actually lives, or null when there is none.
     *
     * Tolerates the older setting value, which was a /storage/… URL rather than
     * a disk path, so an install that already has a logo keeps it.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function path(string $key = 'shop_logo'): ?array
    {
        $value = setting($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $file = ltrim(Str::after($value, '/storage/'), '/');

        // Nothing outside the branding folder, whatever ends up in the setting.
        if (! Str::startsWith($file, 'branding/') || str_contains($file, '..')) {
            return null;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($file)) {
                return [$disk, $file];
            }
        }

        return null;
    }

    /** A URL that changes when the logo does, so browsers pick the new one up. */
    public static function url(): ?string
    {
        return self::path() === null
            ? null
            : route('branding.logo', ['v' => substr(md5((string) setting('shop_logo')), 0, 8)]);
    }

    /** The same for the A5 invoice paper; null while the shop has none. */
    public static function paperUrl(): ?string
    {
        return self::path(self::PAPER) === null
            ? null
            : route('branding.paper', ['v' => substr(md5((string) setting(self::PAPER)), 0, 8)]);
    }

    /** The same for the invoice font; null while the shop has none. */
    public static function fontUrl(): ?string
    {
        return self::path(self::FONT) === null
            ? null
            : route('branding.font', ['v' => substr(md5((string) setting(self::FONT)), 0, 8)]);
    }
}
