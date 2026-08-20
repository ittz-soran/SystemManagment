<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ActivityLogger;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Section 8c: three layers, because they have different owners and different
 * lifetimes. Layer 3 (per-user preferences) lives on the users row and is
 * handled by PreferenceController, not here.
 *
 * The whole page is guarded by `settings.manage` — these values change
 * invoices, costing and the edit window across the entire system.
 */
class SettingController extends Controller
{
    /** Layer 1 — shop info, printed on invoices and shown on the login page. */
    private const SHOP_KEYS = [
        'shop_name', 'shop_name_ku', 'shop_name_ar', 'shop_name_fa',
        'shop_address', 'shop_phone', 'shop_phone_2',
        'shop_email', 'shop_website', 'invoice_footer',
    ];

    /** Layer 2 — appearance, applied as CSS custom properties in the layout. */
    private const APPEARANCE_KEYS = [
        'primary_color', 'secondary_color', 'font_family', 'sidebar_style', 'default_theme',
    ];

    /** The operational values Section 8c says also belong on this page. */
    private const OPERATIONAL_KEYS = [
        'timezone', 'usd_rate', 'books_closed_before',
        'low_stock_threshold', 'sku_prefix', 'date_format',
    ];

    /**
     * Section 8c: "Offer a short vetted list rather than a free-text box."
     * All four render Latin and Arabic script well, so the interface never
     * falls back to an ugly system font mid-sentence.
     */
    public const FONTS = [
        "'Noto Sans Arabic', system-ui, sans-serif" => 'Noto Sans Arabic',
        "'Cairo', system-ui, sans-serif" => 'Cairo',
        "'Vazirmatn', system-ui, sans-serif" => 'Vazirmatn',
        "'Tajawal', system-ui, sans-serif" => 'Tajawal',
        'system-ui, sans-serif' => 'System default',
    ];

    public function __construct(private ActivityLogger $logger) {}

    public function edit(): View
    {
        return view('settings.edit', [
            'shopKeys' => self::SHOP_KEYS,
            'appearanceKeys' => self::APPEARANCE_KEYS,
            'operationalKeys' => self::OPERATIONAL_KEYS,
            'fonts' => self::FONTS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:255'],
            'shop_name_ku' => ['nullable', 'string', 'max:255'],
            'shop_name_ar' => ['nullable', 'string', 'max:255'],
            'shop_name_fa' => ['nullable', 'string', 'max:255'],
            'shop_address' => ['nullable', 'string', 'max:500'],
            'shop_phone' => ['nullable', 'string', 'max:32'],
            'shop_phone_2' => ['nullable', 'string', 'max:32'],
            'shop_email' => ['nullable', 'email', 'max:255'],
            'shop_website' => ['nullable', 'string', 'max:255'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],

            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_family' => ['required', 'string', 'max:255'],
            'sidebar_style' => ['required', 'in:expanded,collapsed'],
            'default_theme' => ['required', 'in:light,dark'],

            'timezone' => ['required', 'timezone'],
            'usd_rate' => ['required', 'integer', 'min:1'],
            'books_closed_before' => ['nullable', 'date'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'sku_prefix' => ['required', 'string', 'max:8'],
            'date_format' => ['required', 'string', 'max:32'],

            // Section 8c: store logos as files with only the path in settings —
            // never base64 in the database.
            'shop_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $previous = Setting::cached();

        DB::transaction(function () use ($data, $request) {
            foreach ($data as $key => $value) {
                if ($key === 'shop_logo') {
                    continue;
                }

                Setting::put($key, $value);
            }

            if ($request->hasFile('shop_logo')) {
                $path = $request->file('shop_logo')->store('branding', 'public');
                Setting::put('shop_logo', Storage::url($path));
            }
        });

        // Clearing the cache is what stops a stale logo or shop name looking
        // broken after a save. Setting::saved() does it, but be explicit here
        // because several rows changed inside one transaction.
        Setting::flushCache();

        $this->logger->log(
            action: 'update',
            module: 'settings',
            description: __('Updated shop settings'),
            oldValues: array_intersect_key($previous, $data),
        );

        return back()->with('success', __('Settings saved'));
    }

    /** Puts every key back to the value the seeder ships. */
    public function reset(): RedirectResponse
    {
        $previous = Setting::cached();

        DB::transaction(function () {
            foreach (SettingSeeder::DEFAULTS as $key => $value) {
                Setting::put($key, $value);
            }
        });

        Setting::flushCache();

        $this->logger->log(
            action: 'update',
            module: 'settings',
            description: __('Reset settings to their defaults'),
            oldValues: $previous,
        );

        return back()->with('success', __('Settings reset to their defaults'));
    }
}
