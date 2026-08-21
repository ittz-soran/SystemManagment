<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Rules\WritableDirectory;
use App\Services\ActivityLogger;
use App\Services\BackupService;
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

    /** Section 8b — how, when and where backups run. */
    private const BACKUP_KEYS = [
        'backup_frequency', 'backup_time', 'backup_weekday',
        'backup_path', 'backup_remote_path',
        'backup_keep_daily', 'backup_keep_monthly',
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

    /**
     * Carbon's day numbering, which is what Schedule::weeklyOn() takes.
     *
     * @return array<int, string>
     */
    public static function weekdays(): array
    {
        return [
            0 => __('Sunday'), 1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'),
            4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'),
        ];
    }

    public function edit(): View
    {
        $backups = app(BackupService::class);

        return view('settings.edit', [
            'shopKeys' => self::SHOP_KEYS,
            'appearanceKeys' => self::APPEARANCE_KEYS,
            'operationalKeys' => self::OPERATIONAL_KEYS,
            'backupKeys' => self::BACKUP_KEYS,
            'fonts' => self::FONTS,
            'weekdays' => self::weekdays(),
            // Section 8c lists backup status on this page: the last backup time
            // and a manual "Back up now" button.
            'lastBackupAt' => $backups->lastRunAt(),
            'backupRemote' => $backups->remotePath(),
            'dailyCopies' => count($backups->copies('daily')),
            'monthlyCopies' => count($backups->copies('monthly')),
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

            'backup_frequency' => ['required', 'in:daily,weekly'],
            'backup_time' => ['required', 'date_format:H:i'],
            'backup_weekday' => ['required', 'integer', 'between:0,6'],
            // Checked now rather than at 02:15 by a cron job nobody is watching.
            'backup_path' => ['nullable', 'string', 'max:255', new WritableDirectory],
            'backup_remote_path' => ['nullable', 'string', 'max:255', new WritableDirectory],
            'backup_keep_daily' => ['required', 'integer', 'min:1', 'max:3650'],
            'backup_keep_monthly' => ['required', 'integer', 'min:1', 'max:120'],

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

    /**
     * Section 8b's "Back up now" button.
     *
     * Runs in the request rather than on a queue, because Section 9b asks long
     * actions to show progress rather than a frozen screen and the shop has no
     * worker process — the button reports what happened when it returns.
     */
    public function backup(Request $request, BackupService $backups): RedirectResponse
    {
        try {
            $result = $backups->run($request->user());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = __('Backed up :size to :path', [
            'size' => $backups->humanSize($result['bytes']),
            'path' => $result['remote'] ?? $result['path'],
        ]);

        // A backup sitting on the same disk as the database is worth saying out
        // loud, so the warning is the message rather than a footnote to it.
        return $result['warnings'] === []
            ? back()->with('success', $message)
            : back()->with('warning', $message.' — '.implode(' ', $result['warnings']));
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
