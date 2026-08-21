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
     * @return array{hex: string, rgb: string, hover: string, active: string, subtle: string, on: string}
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
        $channel = fn (int $value) => ($v = $value / 255) <= 0.03928
            ? $v / 12.92
            : (($v + 0.055) / 1.055) ** 2.4;

        $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);

        return [
            'hex' => strtolower($hex),
            'rgb' => "{$r}, {$g}, {$b}",
            'hover' => $mix(-0.15),
            'active' => $mix(-0.20),
            'subtle' => $mix(0.80),
            'on' => $luminance > 0.55 ? '#000' : '#fff',
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
