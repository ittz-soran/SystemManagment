<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Section 8c: the three settings layers. Layer 3 (user preferences) lives on the
 * users row, not here.
 */
class SettingSeeder extends Seeder
{
    /** @var array<string, string|null> */
    public const DEFAULTS = [
        // Layer 1 — shop info, printed on invoices and shown on the login page.
        'shop_name' => 'Soran Store',
        'shop_name_ku' => null,
        'shop_name_ar' => null,
        'shop_name_fa' => null,
        'shop_address' => null,
        'shop_phone' => null,
        'shop_phone_2' => null,
        'shop_email' => null,
        'shop_website' => null,
        // A path only. Never base64 in the database.
        'shop_logo' => null,
        'invoice_footer' => null,

        // Layer 2 — appearance. Emitted as CSS custom properties in the layout
        // head; stylesheets are never regenerated at runtime.
        'primary_color' => '#0d6efd',
        'secondary_color' => '#6c757d',
        // Vetted for Latin + Arabic-script coverage, self-hosted.
        'font_family' => "'Noto Sans Arabic', system-ui, sans-serif",
        'sidebar_style' => 'expanded',
        'default_theme' => 'light',

        // Operational values.
        'timezone' => 'Asia/Baghdad',
        'usd_rate' => '1320',
        'books_closed_before' => null,
        'low_stock_threshold' => '5',
        'sku_prefix' => 'SS',
        'date_format' => 'Y-m-d',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        Setting::flushCache();
    }
}
