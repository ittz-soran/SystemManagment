<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve the shop's logo.
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
    public function logo(): Response
    {
        $path = self::path();

        abort_if($path === null, 404);

        [$disk, $file] = $path;

        return response()->file(Storage::disk($disk)->path($file), [
            // The URL carries a hash of the stored path, so a new logo is a new
            // URL and this can be cached hard.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Where the logo actually lives, or null when there is none.
     *
     * Tolerates the older setting value, which was a /storage/… URL rather than
     * a disk path, so an install that already has a logo keeps it.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function path(): ?array
    {
        $value = setting('shop_logo');

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
}
