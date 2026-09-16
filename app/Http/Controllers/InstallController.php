<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
// ⚠️ Symfony's, not Illuminate's: response()->file() hands back a
// BinaryFileResponse, which extends this one and not Illuminate\Http\Response.
// BrandingController imports the same one, for the same reason.
use Symfony\Component\HttpFoundation\Response;

/**
 * Putting the shop on a phone's home screen.
 *
 * Soran opened the shop on his phone and it was a web page in a browser. The
 * browser's own bars take about ninety pixels of an eight-hundred-pixel screen
 * — and more than that, a page among twenty tabs is not a thing a shopkeeper
 * reaches for at a counter. Installed, it opens on its own, full height, with
 * the shop's name and logo under it.
 *
 * ⚠️ **Everything here is per shop, so none of it can be a static file.** One
 * codebase serves many shops (Section 8d); each has its own name, its own logo
 * and its own brand colour, and each is installed separately on its own phone.
 * A `public/manifest.json` would give every customer's phone the same name.
 */
class InstallController extends Controller
{
    /** The sizes Android and iOS actually ask for. */
    public const SIZES = [192, 512];

    /**
     * The manifest, built from this shop's settings.
     *
     * Outside the auth group on purpose: a phone fetches the manifest while
     * showing the login page, before anybody has signed in. It says the shop's
     * name and colour, which the login page already says.
     */
    public function manifest(): JsonResponse
    {
        $name = (string) setting('shop_name', config('app.name'));
        $colour = self::brandColour();

        /*
         * ⚠️ The base path, not '/'. A shop can be installed in a subdirectory
         * — the layout already carries `data-base` for exactly this reason —
         * and a scope of '/' would either refuse to install or capture the
         * whole domain, including another shop beside it.
         */
        $base = rtrim(parse_url(url('/'), PHP_URL_PATH) ?: '', '/').'/';

        return response()->json([
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'id' => $base,
            'start_url' => $base,
            'scope' => $base,
            'display' => 'standalone',

            // The colour behind the icon while it opens, and the colour of the
            // phone's own status bar once it has. Both the shop's own.
            'background_color' => $colour,
            'theme_color' => $colour,

            // Section 2: the interface runs in four languages, three of them
            // right to left, and the phone lays the app's name out accordingly.
            'lang' => app()->getLocale(),
            'dir' => in_array(app()->getLocale(), ['ckb', 'ar', 'fa'], true) ? 'rtl' : 'ltr',

            'icons' => collect(self::SIZES)->map(fn (int $size) => [
                'src' => route('install.icon', ['size' => $size, 'v' => $this->iconVersion()]),
                'sizes' => $size.'x'.$size,
                'type' => 'image/png',
                // "any" so it can be used plainly, "maskable" so Android may
                // crop it to whatever shape that phone uses — the icon is drawn
                // with room around the mark for exactly that.
                'purpose' => 'any maskable',
            ])->all(),
        ], 200, [
            'Content-Type' => 'application/manifest+json',
        ]);
    }

    /**
     * The app icon: the shop's own logo on the shop's own colour.
     *
     * Drawn rather than uploaded. Asking a shopkeeper for a 512-pixel square
     * PNG is asking for a job nobody will do, and the shop already has a logo
     * on its invoices — this puts that logo on a square of the brand colour,
     * which is what a home screen wants.
     *
     * ⚠️ **GD may not be there.** It is on this machine; cPanel hosting varies,
     * and a missing extension must not be a broken icon on a customer's phone.
     * Without it — or without a logo — the shop gets a plain square in its own
     * colour, which is a perfectly good icon and needs no extension at all.
     */
    public function icon(int $size): Response
    {
        abort_unless(in_array($size, self::SIZES, true), 404);

        $cached = storage_path('app/icons/'.$this->iconVersion().'-'.$size.'.png');

        if (! is_file($cached)) {
            @mkdir(dirname($cached), 0755, true);
            file_put_contents($cached, $this->draw($size));
        }

        return response()->file($cached, [
            'Content-Type' => 'image/png',
            // The URL carries a hash of the logo and the colour, so changing
            // either is a new URL and this can be cached hard.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The service worker.
     *
     * ⚠️ **It caches the build assets and nothing else — never a page, never a
     * figure.** The temptation with a service worker is to make the shop work
     * offline. Do not: a till showing yesterday's stock out of a cache is worse
     * than a till showing an error, because the error is obvious and the stale
     * number is not. The shop already tells a reader when it has lost the
     * server — that is the honest answer to being offline, and it is built.
     *
     * So this exists for one reason: a browser will not offer "install" without
     * one. It passes every request straight through except the hashed files
     * under the build directory, whose names change whenever their contents do,
     * which makes them safe to keep forever.
     */
    public function serviceWorker(): Response
    {
        $base = rtrim(parse_url(url('/'), PHP_URL_PATH) ?: '', '/');

        $js = <<<JS
        // Generated by App\\Http\\Controllers\\InstallController. Do not edit by
        // hand — it is per shop, and it is served, not stored.
        const BUILD = '{$base}/build/';
        const SHELF = 'assets-v1';

        self.addEventListener('install', () => self.skipWaiting());
        self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

        self.addEventListener('fetch', (event) => {
            const url = new URL(event.request.url);

            // Anything that is not a hashed build asset goes to the server,
            // every time. A page, a search, a total: always fresh or nothing.
            if (event.request.method !== 'GET'
                || url.origin !== self.location.origin
                || ! url.pathname.startsWith(BUILD)) {
                return;
            }

            event.respondWith(
                caches.open(SHELF).then(async (shelf) => {
                    const kept = await shelf.match(event.request);

                    if (kept) {
                        return kept;
                    }

                    const fresh = await fetch(event.request);

                    if (fresh.ok) {
                        shelf.put(event.request, fresh.clone());
                    }

                    return fresh;
                })
            );
        });
        JS;

        return response($js, 200, [
            'Content-Type' => 'text/javascript',
            // ⚠️ Never cached. A stale service worker is the one file that
            // cannot be corrected by a refresh, because it is what answers the
            // refresh.
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * A hash of everything the icon is drawn from.
     *
     * In the icon's URL, so a shop that changes its logo or its colour gets a
     * new icon on the next install rather than the one the phone cached.
     */
    public function iconVersion(): string
    {
        return substr(md5((string) setting('shop_logo').'|'.self::brandColour()), 0, 8);
    }

    /**
     * The shop's own colour, or Bootstrap's blue before anybody picks one.
     *
     * ⚠️ Through `brand_palette()`, which is where this shop sanitises a colour
     * — not a second regex of my own. `primary_color` is typed by a person and
     * AppearanceTest exists because somebody can type
     * `red; } body { display: none` into it. One validator means one place to
     * be right, and the meta tag in the layout goes through the same one.
     */
    public static function brandColour(): string
    {
        return brand_palette((string) setting('primary_color', '#0d6efd'))['hex'];
    }

    /** The icon itself, as PNG bytes. */
    private function draw(int $size): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return $this->plainSquare($size);
        }

        $canvas = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = $this->rgb();
        imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocate($canvas, $r, $g, $b));

        $logo = $this->logoImage();

        if ($logo !== null) {
            /*
             * ⚠️ Inside the middle 60%, not edge to edge. Android crops a
             * maskable icon to a circle on some phones and a squircle on
             * others, and anything closer to the edge than this loses corners.
             */
            $room = (int) round($size * 0.6);
            $w = imagesx($logo);
            $h = imagesy($logo);
            $scale = min($room / $w, $room / $h);
            $drawW = max(1, (int) round($w * $scale));
            $drawH = max(1, (int) round($h * $scale));

            imagealphablending($canvas, true);
            imagecopyresampled(
                $canvas, $logo,
                (int) round(($size - $drawW) / 2), (int) round(($size - $drawH) / 2),
                0, 0, $drawW, $drawH, $w, $h,
            );

            imagedestroy($logo);
        }

        ob_start();
        imagepng($canvas);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    /** The shop's logo as a GD image, or null when there is none it can read. */
    private function logoImage(): ?\GdImage
    {
        $path = BrandingController::path();

        if ($path === null) {
            return null;
        }

        [$disk, $file] = $path;
        $full = Storage::disk($disk)->path($file);

        // An upload is whatever the shopkeeper had — a PNG, a JPEG, sometimes
        // something GD cannot read at all. A logo it cannot open is not an
        // error; it is a shop with a plain icon.
        $image = @imagecreatefromstring((string) @file_get_contents($full));

        return $image === false ? null : $image;
    }

    /**
     * A square of the shop's colour, written byte by byte.
     *
     * The fallback for hosting without GD. A single-pixel PNG scaled by the
     * phone — small, valid, and the right colour, which is most of what an icon
     * on a home screen is doing at that size anyway.
     */
    private function plainSquare(int $size): string
    {
        [$r, $g, $b] = $this->rgb();

        $signature = "\x89PNG\r\n\x1a\n";
        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        // One pixel, no filter, truecolour, 8 bits.
        $header = $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0));
        $pixels = $chunk('IDAT', gzcompress(chr(0).chr($r).chr($g).chr($b)));

        return $signature.$header.$pixels.$chunk('IEND', '');
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(): array
    {
        $hex = ltrim(self::brandColour(), '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
