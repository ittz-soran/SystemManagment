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
