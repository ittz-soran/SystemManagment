<?php

use App\Models\Setting;
use Carbon\CarbonInterface;

if (! function_exists('setting')) {
    /**
     * Section 8c: views never touch the Setting model directly.
     * Backed by a forever cache that is flushed whenever settings are saved.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        $value = Setting::cached()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }
}

if (! function_exists('money')) {
    /**
     * Section 2: IQD is always whole numbers, displayed with thousands separators.
     *
     * Section 9b: numbers and currency stay left-to-right even inside RTL text,
     * which the caller handles with `dir="ltr"` on the containing element.
     */
    function money(int|float|null $amount, bool $withCurrency = true): string
    {
        $formatted = number_format((int) $amount);

        return $withCurrency ? $formatted.' '.__('IQD') : $formatted;
    }
}

if (! function_exists('hidden_money')) {
    /**
     * A figure this reader is not allowed to see.
     *
     * Shown rather than removed. A missing tile tells somebody the shop has no
     * such number; a masked one tells them there is a number and it is not
     * theirs — which is the truth, and is what the shopkeeper asked for after
     * the tiles went away.
     */
    function hidden_money(): string
    {
        return '*****';
    }
}

if (! function_exists('money_if')) {
    /**
     * `money()`, or the mask when this reader may not see the figure.
     *
     * @param  bool  $visible  whatever the screen's own rule is — usually a permission
     */
    function money_if(bool $visible, int|float|null $amount, bool $withCurrency = true): string
    {
        if (! $visible) {
            return $withCurrency ? hidden_money().' '.__('IQD') : hidden_money();
        }

        return money($amount, $withCurrency);
    }
}

if (! function_exists('cost_seen')) {
    /**
     * A cost figure as the signed-in reader is allowed to work from it.
     *
     * Null when they may not see cost at all. Every figure derived from a cost —
     * a line's value, the shelf's worth, a profit — is built from this rather
     * than from the stored number, so what is on screen adds up and the real
     * cost is not one subtraction away from a marked-up one.
     */
    function cost_seen(?int $amount): ?int
    {
        $reader = auth()->user();

        // Nobody signed in is a console command or a scheduled job, which has
        // no counter staff to keep a figure from.
        return $reader ? $reader->costAsSeen($amount) : $amount;
    }
}

if (! function_exists('cost_money')) {
    /** A cost, formatted as this reader may see it — or the mask. */
    function cost_money(?int $amount, bool $withCurrency = true): string
    {
        $seen = cost_seen($amount);

        return money_if($seen !== null, $seen, $withCurrency);
    }
}

if (! function_exists('books_closed_on')) {
    /**
     * Section 8: nothing dated before settings.books_closed_before can be
     * created, edited, or deleted.
     */
    function books_closed_on(CarbonInterface|string|null $date): bool
    {
        $closedBefore = setting('books_closed_before');

        if (! $closedBefore || ! $date) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($date)->startOfDay()
            ->lt(\Illuminate\Support\Carbon::parse($closedBefore)->startOfDay());
    }
}

if (! function_exists('brand_palette')) {
    /**
     * Everything the interface needs derived from one hex colour.
     *
     * Section 8c emits the shop's brand as CSS custom properties, but Bootstrap
     * 5.3 compiles component colours from SCSS at build time: .btn-primary
     * carries a literal --bs-btn-bg: #0d6efd, not var(--bs-primary). Overriding
     * the variable alone changes almost nothing visible, which is exactly what
     * "I picked a colour and nothing happened" looks like. So the shades and the
     * readable foreground are worked out here and written into the component
     * variables instead.
     *
     * @return array{hex: string, rgb: string, hover: string, active: string, subtle: string, on: string, on_light: string, on_light_rgb: string, on_dark: string, on_dark_rgb: string}
     */
    function brand_palette(string $hex, string $fallback = '#0d6efd'): array
    {
        if (! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            $hex = $fallback;
        }

        [$r, $g, $b] = array_map(hexdec(...), str_split(ltrim($hex, '#'), 2));

        $mix = function (float $amount) use ($r, $g, $b) {
            // Negative darkens towards black, positive lightens towards white —
            // the same shape as Bootstrap's own shade-color/tint-color.
            $blend = fn (int $channel) => (int) round(
                $amount < 0 ? $channel * (1 + $amount) : $channel + (255 - $channel) * $amount
            );

            return sprintf('#%02x%02x%02x', $blend($r), $blend($g), $blend($b));
        };

        // Relative luminance, so a pale brand colour gets dark text on it rather
        // than the white that Bootstrap hardcodes.
        $channel = fn (float $value) => ($v = $value / 255) <= 0.03928
            ? $v / 12.92
            : (($v + 0.055) / 1.055) ** 2.4;

        $luminanceOf = function (string $colour) use ($channel): float {
            [$cr, $cg, $cb] = array_map(hexdec(...), str_split(ltrim($colour, '#'), 2));

            return 0.2126 * $channel($cr) + 0.7152 * $channel($cg) + 0.0722 * $channel($cb);
        };

        $tripletOf = fn (string $colour): string => implode(', ', array_map(
            hexdec(...),
            str_split(ltrim($colour, '#'), 2),
        ));

        $luminance = $luminanceOf($hex);

        /*
         * The same colour, moved until it can be read as text on a given page.
         *
         * A brand colour is picked to look right as a filled button, where the
         * text sits on top of it. Used as text itself — an outline button, an
         * icon, a link — it has to carry the contrast on its own, and the
         * default grey does not: #6c757d on Bootstrap's dark page is 2.8:1,
         * which is why the buttons on a dark screen read as smudges.
         *
         * So it is walked away from the page's own colour, five per cent at a
         * time, until it clears 4.5:1 — WCAG AA for body text. Twenty steps is
         * the whole distance to white or to black, so the loop always ends.
         */
        $readableOn = function (string $background) use ($mix, $luminanceOf): string {
            $backgroundLuminance = $luminanceOf($background);
            $lighten = $backgroundLuminance < 0.5;

            for ($step = 0; $step <= 20; $step++) {
                $candidate = $mix($lighten ? $step * 0.05 : $step * -0.05);
                $candidateLuminance = $luminanceOf($candidate);

                $ratio = (max($candidateLuminance, $backgroundLuminance) + 0.05)
                    / (min($candidateLuminance, $backgroundLuminance) + 0.05);

                if ($ratio >= 4.5) {
                    return $candidate;
                }
            }

            return $lighten ? '#ffffff' : '#000000';
        };

        return [
            'hex' => strtolower($hex),
            'rgb' => "{$r}, {$g}, {$b}",
            'hover' => $mix(-0.15),
            'active' => $mix(-0.20),
            'subtle' => $mix(0.80),
            'on' => $luminance > 0.55 ? '#000' : '#fff',

            /*
             * Readable as text on either theme's page.
             *
             * Measured against the least helpful surface each theme puts behind
             * a button, not the most: in dark that is the tertiary grey a card
             * header and a toolbar use (#2b3035), not the body's #212529, and in
             * light it is the page's own #f8f9fa rather than a white card. Aim
             * at the easy one and every button sitting on the other misses.
             */
            'on_light' => $readableOn('#f8f9fa'),
            'on_light_rgb' => $tripletOf($readableOn('#f8f9fa')),
            'on_dark' => $readableOn('#2b3035'),
            'on_dark_rgb' => $tripletOf($readableOn('#2b3035')),
        ];
    }
}

if (! function_exists('shop_logo')) {
    /**
     * The URL of the shop's logo, or null when there is none on disk.
     *
     * Null rather than a broken image: a setting can name a file that has since
     * been deleted, and every caller guards on this.
     */
    function shop_logo(): ?string
    {
        return \App\Http\Controllers\BrandingController::url();
    }
}

if (! function_exists('after_delete')) {
    /**
     * Where to send the reader once a document is deleted.
     *
     * Deleting from a list leaves the list standing, so they stay on it, filters
     * and page intact. Deleting from the document's own page is the awkward one:
     * that page has just stopped existing, and the list it belonged to is a
     * guess — opening an invoice from the second-hand book and deleting it
     * should not land on the sales history. So the form carries the page behind
     * it, filled in by app.js from the tab's own history, and that is where they
     * go. The list is the fallback for a bookmark, or a browser with scripting
     * off.
     *
     * Only a path on this site is ever followed. The carried value comes from
     * the page, so it is treated as something a visitor typed: an absolute URL,
     * a protocol-relative one, or anything that is not a plain path is dropped
     * rather than turned into a redirect off the shop's own site.
     *
     * @param  string  $gone      the URL of the page that no longer exists
     * @param  string  $fallback  the list it belonged to
     */
    function after_delete(string $gone, string $fallback): string
    {
        // Compared by path, never by the whole address.
        //
        // The shop answers to more than one address — the same machine is
        // http://localhost:8000 to whoever set it up and http://127.0.0.1:8000
        // to whoever typed that instead, and over the counter it is the
        // machine's name on the network. The referer carries whichever the
        // reader actually typed; route() builds whichever APP_URL happens to
        // say. Compared as strings those two never match, so the guard below
        // used to think the deleted record's own page was somewhere else worth
        // going — and sent the reader to a row that no longer exists. A 404,
        // every time, for a delete that had worked perfectly.
        $path = static function (string $url): string {
            $path = parse_url($url, PHP_URL_PATH);

            return $path === false || $path === null || $path === '' ? '/' : $path;
        };

        $previous = url()->previous();
        $gonePath = $path($gone);

        // previous() hands back the Referer header as the browser sent it, and
        // a browser will send one from anywhere. A reader who followed a link
        // into this shop from another site and then deleted something would be
        // shown the door back out to that site — carrying the shop's flash
        // message with them. Only this shop's own pages are somewhere to put
        // anybody.
        $host = static fn (string $url): ?string => parse_url($url, PHP_URL_HOST) ?: null;
        $sameSite = $host($previous) === null || $host($previous) === $host(url()->current());

        // previous() answers with the site root when the browser sent no
        // referer at all, which is not a page in this shop and not somewhere to
        // put anybody.
        $hasPrevious = $sameSite
            && $path($previous) !== '/'
            && $path($previous) !== $gonePath
            && $path($previous) !== $path(url()->current());

        if ($hasPrevious) {
            return $previous;
        }

        $carried = (string) request()->input('return_to');

        $safe = str_starts_with($carried, '/')
            && ! str_starts_with($carried, '//')
            && ! str_contains($carried, '\\')
            && $path($carried) !== $gonePath;

        return $safe ? $carried : $fallback;
    }
}
