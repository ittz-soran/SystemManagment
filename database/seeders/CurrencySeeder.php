<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * The currencies a shop starts with — Section 2b.
 *
 * Two rows, and neither is a guess. IQD is the base, and its `decimals` is
 * carried over from `currency_minor_per_major` so a shop that has already been
 * running keeps whatever it was reading. USD exists because Section 6b's helper
 * already assumes it does, and its rate is the `usd_rate` that helper uses —
 * one number in one place instead of two that can drift.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        // What the shop was already reading, if it has been running a while.
        $per = max(1, (int) setting('currency_minor_per_major', 1));
        $decimals = in_array($per, [1, 10, 100, 1000], true) ? (int) log10($per) : 0;

        Currency::firstOrCreate(['code' => 'IQD'], [
            'name' => 'Iraqi Dinar',
            // Left null on purpose: __('IQD') already renders د.ع in Kurdish and
            // Arabic, and a hard-coded symbol here would be the same word in
            // every language.
            'symbol' => null,
            'decimals' => $decimals,
            'rate' => (10 ** $decimals) * Money::RATE_SCALE,
            'is_active' => true,
        ]);

        Currency::firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimals' => 2,
            // Section 6b's own rate, scaled. A shop that has never touched the
            // setting gets its default, which is what the purchase screen has
            // been offering all along.
            'rate' => max(1, (int) setting('usd_rate', 1320)) * Money::RATE_SCALE,
            'is_active' => true,
        ]);
    }
}
