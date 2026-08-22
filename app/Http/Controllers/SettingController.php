<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Rules\WritableDirectory;
use App\Services\ActivityLogger;
use App\Services\BackupService;
use App\Services\LabelPrinter;
use App\Services\LabelService;
use App\Services\SystemResetService;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

    /** Section 4 — barcode labels: the printer, the stock, and what is on them. */
    private const LABEL_KEYS = [
        'label_printer', 'label_size',
        'label_show_name', 'label_show_sku', 'label_show_price',
        'label_show_barcode_number', 'label_show_shop',
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
            'labelKeys' => self::LABEL_KEYS,
            'labelSizes' => config('labels.sizes'),
            'labelFields' => app(LabelService::class)->fields(),
            // Best-effort: a machine that cannot be asked gets the text box.
            'detectedPrinters' => app(LabelPrinter::class)->available(),
            'fonts' => self::FONTS,
            'weekdays' => self::weekdays(),
            // Section 8c lists backup status on this page: the last backup time
            // and a manual "Back up now" button.
            'lastBackupAt' => $backups->lastRunAt(),
            // The "Start fresh" card shows what would go before it offers to go.
            'resetPreview' => app(SystemResetService::class)->preview(),
            'resetBlocker' => app(SystemResetService::class)->blocker(),
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

            'label_printer' => ['nullable', 'string', 'max:255'],
            'label_size' => ['required', Rule::in(array_keys(config('labels.sizes')))],

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

            // A checkbox posts nothing when it is unticked, so absence has to
            // be written as off — otherwise a field could never be turned off.
            foreach (LabelService::FIELDS as $field) {
                // '0', never null: setting() treats null and '' as "no value
                // set" and hands back the default, so a null would read as ON
                // and the field could never be switched off.
                Setting::put('label_show_'.$field, $request->boolean('label_show_'.$field) ? '1' : '0');
            }

            if ($request->hasFile('shop_logo')) {
                $previous = BrandingController::path();

                // The disk path, not a URL: BrandingController serves the file,
                // so nothing depends on the /storage symlink existing.
                Setting::put('shop_logo', $request->file('shop_logo')->store('branding', 'local'));

                if ($previous !== null) {
                    Storage::disk($previous[0])->delete($previous[1]);
                }
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

    /**
     * "Start fresh": clear everything entered while testing, keep the catalogue.
     *
     * Guarded three ways, because there is no undo beyond the backup it takes:
     * the shop's own name has to be typed, a frozen period blocks it outright,
     * and it is only reachable by someone who can manage settings.
     */
    public function resetTransactions(Request $request, SystemResetService $reset): RedirectResponse
    {
        $expected = (string) setting('shop_name', config('app.name'));

        $request->validate([
            'confirmation' => ['required', 'string', Rule::in([$expected])],
        ], [
            'confirmation.in' => __('Type :name exactly to confirm.', ['name' => $expected]),
        ]);

        try {
            $result = $reset->run($request->user());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Cleared :summary. A backup was saved first as :backup.', [
            'summary' => $reset->summarise($result['removed']),
            'backup' => basename($result['backup']),
        ]));
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
