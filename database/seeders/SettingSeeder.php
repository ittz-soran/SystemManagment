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
        //
        // Overridden per install from SHOP_NAME — see run(). This value is the
        // last resort and is deliberately nobody's real shop: it used to be the
        // seller's own, which meant every customer opened their till on the
        // first morning and read somebody else's name above their sales.
        'shop_name' => 'My Shop',
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

        // Section 8b — backups. These override the .env defaults so an admin can
        // change them from the Settings page without touching a file on the
        // server; a null falls back to config/backup.php.
        'backup_frequency' => 'daily',
        'backup_time' => '02:15',
        // Friday, the quiet night of the Iraqi weekend. Only read when the
        // frequency is weekly.
        'backup_weekday' => '5',
        'backup_path' => null,
        'backup_remote_path' => null,
        'backup_keep_daily' => '30',
        'backup_keep_monthly' => '12',

        // Section 4: labels for products whose barcode the system generated.
        // The printer is only reachable when the server runs on the same
        // machine; blank means the browser's print dialog is the only route.
        'label_printer' => null,
        'label_size' => '50x30',
        'label_show_name' => '1',
        'label_show_sku' => '1',
        'label_show_price' => '1',
        'label_show_barcode_number' => '1',
        'label_show_shop' => '0',

        // Documents dated before this are hidden from the day-to-day lists.
        // Nothing is deleted — see PeriodArchiveService.
        'archived_before' => null,
    ];

    public function run(): void
    {
        /*
         * The shop's own name, from whoever is installing it.
         *
         * Same shape as ADMIN_PASSWORD in DatabaseSeeder, and for the same
         * reason: the two things that must be this customer's rather than the
         * default are their name and their password, and both are known at the
         * moment the install is made. SHOP_NAME is set by the panel when it
         * creates a customer, or by hand for a single install.
         *
         * APP_NAME is not used for this, deliberately. It is Laravel's own name
         * for the application and shows up in mail and exception pages; the
         * shop's name is a setting the owner can change afterwards from the
         * Settings page, and the two stop being the same the first time they do.
         */
        $defaults = self::DEFAULTS;

        if (($name = trim((string) env('SHOP_NAME', ''))) !== '') {
            $defaults['shop_name'] = $name;
        }

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        Setting::flushCache();
    }
}
