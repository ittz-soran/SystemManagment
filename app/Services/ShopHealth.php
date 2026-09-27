<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything about this shop that is not its bookkeeping — Soran, 2026-09-27.
 *
 * ⚠️ **Half of the shop's diagnostics existed and he could never reach them.**
 * `DataIntegrityService` put seventeen accounting checks on a web page; the
 * licence, the folders, the compiled assets and the database driver lived only
 * in `php artisan shop:doctor` — a terminal command, on cPanel shared hosting,
 * for a shopkeeper. It may as well not have existed.
 *
 * ⚠️ **One implementation, two ways to read it.** `ShopDoctor` keeps its
 * terminal output and reads these answers rather than holding its own copy. A
 * second copy is how two screens come to disagree, which this shop has learned
 * more than once in a week.
 *
 * ⚠️ **The same check shape as the accounting checks**, so the page renders one
 * thing: a key, a title, a severity, the sentence saying why it matters, and
 * its examples. And the same promise — a check that throws costs you that
 * check, never the page. A diagnostics page is the last page in the shop
 * allowed to answer with a stack trace.
 */
final class ShopHealth
{
    /** The four words the page already speaks. */
    public const OK = DataIntegrityService::OK;

    public const NOTICE = DataIntegrityService::REBUILDABLE;

    public const SERIOUS = DataIntegrityService::SERIOUS;

    public const UNAVAILABLE = DataIntegrityService::UNAVAILABLE;

    /** Below this much free disk, a shop is one backup away from trouble. */
    private const DISK_WARN_MB = 500;

    /** A log this size is either a loop or a fault nobody has read. */
    private const LOG_WARN_MB = 20;

    /** A backup older than this is not a backup anybody is relying on. */
    private const BACKUP_STALE_DAYS = 3;

    /**
     * @return array{
     *     sections: array<string, array{title: string, checks: list<array<string, mixed>>}>,
     *     numbers: array<string, string>,
     *     serious: int, notice: int, unavailable: int, ok: int
     * }
     */
    public function run(): array
    {
        $sections = [
            'licence' => ['title' => __('Licence'), 'checks' => [
                $this->attempt('licence_state', fn () => $this->licenceState()),
            ]],
            'storage' => ['title' => __('Storage'), 'checks' => [
                $this->attempt('disk_space', fn () => $this->diskSpace()),
                $this->attempt('writable', fn () => $this->foldersAreWritable()),
                $this->attempt('backup_age', fn () => $this->backupIsRecent()),
                $this->attempt('log_size', fn () => $this->logIsNotRunaway()),
            ]],
            'codebase' => ['title' => __('Codebase'), 'checks' => [
                $this->attempt('migrations', fn () => $this->everyMigrationHasRun()),
                $this->attempt('assets', fn () => $this->compiledAssetsAreThere()),
                $this->attempt('php', fn () => $this->phpIsSupported()),
            ]],
        ];

        $all = [];

        foreach ($sections as $section) {
            $all = [...$all, ...$section['checks']];
        }

        $count = fn (string $severity) => count(array_filter($all, fn ($c) => $c['severity'] === $severity));

        return [
            'sections' => $sections,
            'numbers' => $this->numbers(),
            'serious' => $count(self::SERIOUS),
            'notice' => $count(self::NOTICE),
            'unavailable' => $count(self::UNAVAILABLE),
            'ok' => $count(self::OK),
        ];
    }

    /**
     * Run one check, and survive it failing.
     *
     * ⚠️ Word for word the reasoning in `DataIntegrityService::attempt()`: a
     * check is a question, but it is also code, and code has its own ways of
     * going wrong. A shop whose `disk_free_space` is disabled by the host must
     * lose that one answer, not the other nine.
     */
    private function attempt(string $key, callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            report($e);

            return $this->verdict(
                key: $key,
                title: $this->titleFor($key),
                because: __('This check could not run, so it says nothing either way about the shop. The other checks on this page are unaffected.'),
                severity: self::UNAVAILABLE,
                examples: [['what' => __('The check itself failed'), 'says' => Str::limit($e->getMessage(), 300)]],
            );
        }
    }

    // =====================================================================
    // Licence
    // =====================================================================

    private function licenceState(): array
    {
        $licence = app(Licence::class);

        if (! $licence->isRequired()) {
            return $this->verdict('licence_state', $this->titleFor('licence_state'),
                __('This copy does not ask for a licence, so there is nothing here to expire.'),
                self::OK);
        }

        $found = $licence->check();
        $days = $found['days_left'];

        /*
         * ⚠️ **Only the states that STOP the shop trading are serious.** A
         * licence inside its grace days still sells; saying "serious" about it
         * would teach the reader that red on this page can be ignored, and then
         * the red that matters is ignored too.
         */
        $stopped = ! $licence->allowsWriting();

        $severity = match (true) {
            $stopped => self::SERIOUS,
            in_array($found['state'], [Licence::EXPIRING, Licence::GRACE], true) => self::NOTICE,
            default => self::OK,
        };

        return $this->verdict('licence_state', $this->titleFor('licence_state'),
            $stopped
                ? __('The shop cannot record anything new until this is put right.')
                : __('A licence that lapses stops the shop writing, so it is worth knowing before the day it does.'),
            $severity,
            $severity === self::OK ? [] : [[
                'what' => __('Licence :state', ['state' => Str::headline((string) $found['state'])]),
                'says' => $days === null
                    ? (string) ($found['host'] ?? '—')
                    : trans_choice('{0}it ran out today|{1}one day left|[2,*]:count days left', max(0, (int) $days), ['count' => number_format(max(0, (int) $days))]),
            ]],
        );
    }

    // =====================================================================
    // Storage
    // =====================================================================

    private function diskSpace(): array
    {
        $free = @disk_free_space(storage_path());

        if ($free === false) {
            throw new \RuntimeException('disk_free_space is not available on this host.');
        }

        $mb = (int) round($free / 1024 / 1024);

        return $this->verdict('disk_space', $this->titleFor('disk_space'),
            __('A shop that runs out of disk cannot write a sale, and the first thing it loses is usually the backup.'),
            $mb < self::DISK_WARN_MB ? self::NOTICE : self::OK,
            $mb < self::DISK_WARN_MB
                ? [['what' => __('Free space'), 'says' => __(':count MB left', ['count' => number_format($mb)])]]
                : [],
            note: __(':count MB free', ['count' => number_format($mb)]),
        );
    }

    private function foldersAreWritable(): array
    {
        $folders = [
            __('Logs') => storage_path('logs'),
            __('Sessions') => storage_path('framework/sessions'),
            __('Compiled views') => storage_path('framework/views'),
            __('Uploads') => storage_path('app'),
        ];

        $failures = [];

        foreach ($folders as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $failures[] = [
                    'what' => $label,
                    'says' => is_dir($path) ? __('not writable') : __('missing'),
                ];
            }
        }

        return $this->verdict('writable', $this->titleFor('writable'),
            __('The shop writes its sessions, its compiled pages and its log here. A folder it cannot write to takes the whole site down, usually after an upload has changed the owner.'),
            $failures === [] ? self::OK : self::SERIOUS,
            $failures,
        );
    }

    private function backupIsRecent(): array
    {
        $last = app(BackupService::class)->lastRunAt();

        if ($last === null) {
            return $this->verdict('backup_age', $this->titleFor('backup_age'),
                __('An untested backup is not a backup, and a backup that has never run is not one either.'),
                self::SERIOUS,
                [['what' => __('Last backup'), 'says' => __('never')]],
                repair: 'settings.edit',
            );
        }

        $days = (int) $last->diffInDays(now());

        return $this->verdict('backup_age', $this->titleFor('backup_age'),
            __('An untested backup is not a backup, and a backup that has never run is not one either.'),
            $days > self::BACKUP_STALE_DAYS ? self::NOTICE : self::OK,
            $days > self::BACKUP_STALE_DAYS
                ? [['what' => __('Last backup'), 'says' => $last->diffForHumans()]]
                : [],
            repair: $days > self::BACKUP_STALE_DAYS ? 'settings.edit' : null,
            note: $last->format(setting('date_format', 'Y-m-d')),
        );
    }

    private function logIsNotRunaway(): array
    {
        $path = storage_path('logs/laravel.log');
        $mb = is_file($path) ? (int) round(filesize($path) / 1024 / 1024) : 0;

        return $this->verdict('log_size', $this->titleFor('log_size'),
            __('A log this big is either something failing in a loop or a fault nobody has read yet. Either way it is worth opening before it fills the disk.'),
            $mb >= self::LOG_WARN_MB ? self::NOTICE : self::OK,
            $mb >= self::LOG_WARN_MB
                ? [['what' => __('Error log'), 'says' => __(':count MB', ['count' => number_format($mb)])]]
                : [],
            note: __(':count MB', ['count' => number_format($mb)]),
        );
    }

    // =====================================================================
    // Codebase
    // =====================================================================

    private function everyMigrationHasRun(): array
    {
        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($path) => basename($path, '.php'));

        $ran = collect(DB::table('migrations')->pluck('migration'));

        $pending = $files->diff($ran)->values();

        return $this->verdict('migrations', $this->titleFor('migrations'),
            __('A migration that has not run means a column the code expects is not there, and the page that needs it answers with an error instead.'),
            $pending->isEmpty() ? self::OK : self::SERIOUS,
            $pending->take(8)->map(fn ($name) => ['what' => __('Not run'), 'says' => $name])->all(),
            repair: null,
            note: trans_choice('{1}:count migration|[2,*]:count migrations', $files->count(), ['count' => number_format($files->count())]),
        );
    }

    /**
     * ⚠️ **Present is not the same as current.** A shop updated by copying the
     * codebase over keeps yesterday's `public/build` unless the assets were
     * copied too — every page then loads a stylesheet that does not match the
     * markup, and it looks like the design broke rather than like a bad deploy.
     */
    /**
     * The five facts about the compiled assets, named.
     *
     * ⚠️ **Public because `shop:doctor` prints them verbatim.** A verdict is
     * what a shopkeeper needs; a person with an SSH session diagnosing a bad
     * deploy needs the hashes and the filename. **One place works them out,
     * two places present them** — a first refactor flattened the command's
     * output into the verdict and quietly destroyed all five, which is a
     * capability loss disguised as a tidy-up.
     *
     * @return array<string, mixed>
     */
    public function assetFacts(): array
    {
        $public = rtrim(defined('SHOP_PUBLIC') ? (string) constant('SHOP_PUBLIC') : public_path(), '/\\');

        $shared = base_path('public/build/manifest.json');
        $theirs = $public.'/build/manifest.json';

        $facts = [
            'shared manifest' => is_file($shared) ? substr(hash_file('sha256', $shared), 0, 12) : 'MISSING',
            'shop manifest' => is_file($theirs) ? substr(hash_file('sha256', $theirs), 0, 12) : 'MISSING',
        ];

        $facts['they match'] = is_file($shared) && is_file($theirs)
            && hash_file('sha256', $shared) === hash_file('sha256', $theirs);

        // Present-and-matching manifests still serve nothing if the stylesheet
        // beside them never arrived.
        $facts['stylesheet'] = null;
        $facts['stylesheet is there'] = false;

        if (is_file($theirs)) {
            $manifest = json_decode((string) file_get_contents($theirs), true);
            $css = $manifest['resources/scss/app.scss']['file'] ?? null;

            $facts['stylesheet'] = $css ?? 'not named in the manifest';
            $facts['stylesheet is there'] = $css !== null && is_file($public.'/build/'.$css);
        }

        return $facts;
    }

    /** @return array<string, mixed> */
    public function licenceFacts(): array
    {
        try {
            $licence = app(Licence::class);

            return ['required' => $licence->isRequired(), 'state' => $licence->state()];
        } catch (Throwable $e) {
            return ['state' => 'could not be read', 'why' => $e->getMessage()];
        }
    }

    private function compiledAssetsAreThere(): array
    {
        $facts = $this->assetFacts();

        $failures = [];

        if ($facts['shop manifest'] === 'MISSING') {
            $failures[] = ['what' => __('The manifest'), 'says' => __('missing')];
        } elseif (! $facts['they match']) {
            $failures[] = ['what' => __('The manifest'), 'says' => __('older than the codebase')];
        }

        if (! $facts['stylesheet is there']) {
            $failures[] = ['what' => __('The stylesheet'), 'says' => $facts['stylesheet'] ?? __('missing')];
        }

        return $this->verdict('assets', $this->titleFor('assets'),
            __('Every screen loads these. When they are missing or older than the code, the shop looks broken rather than out of date.'),
            $failures === [] ? self::OK : self::SERIOUS,
            $failures,
            note: $facts['stylesheet'],
        );
    }

    /**
     * ⚠️ **Every extension here is one the code actually calls, and the finding
     * names the feature that stops without it.**
     *
     * A first version of this check listed six extensions from memory —
     * including `bcmath`, which `composer.json` does not require and which this
     * shop calls nowhere. It would have put a red "PHP is broken" on a
     * perfectly healthy machine. **A diagnostics page that cries wolf is worse
     * than no diagnostics page**, because the next red thing is ignored too.
     *
     * So: `pdo` and `mbstring` are Laravel itself; `openssl` verifies the
     * licence; `zip` writes the period archive; `gd` draws the app icons at
     * install. Nothing is listed that cannot be pointed at a caller.
     */
    private function phpIsSupported(): array
    {
        $version = PHP_VERSION;

        $needed = [
            'pdo' => __('the database'),
            'mbstring' => __('almost everything'),
            'openssl' => __('the licence'),
            'zip' => __('archiving a period'),
            'gd' => __('the app icons'),
        ];

        $failures = [];

        foreach ($needed as $extension => $feature) {
            if (! extension_loaded($extension)) {
                $failures[] = [
                    'what' => $extension,
                    'says' => __('missing — :feature stops working', ['feature' => $feature]),
                ];
            }
        }

        // ⚠️ Below the floor is serious; above it is simply the version.
        if (version_compare($version, '8.3', '<')) {
            $failures[] = ['what' => __('PHP version'), 'says' => __(':version — this shop needs 8.3 or newer', ['version' => $version])];
        }

        return $this->verdict('php', $this->titleFor('php'),
            __('The shop is written for PHP 8.3 and newer. A host that quietly moves the version, or drops an extension, is the commonest cause of a page that worked yesterday.'),
            $failures === [] ? self::OK : self::SERIOUS,
            $failures,
            note: $version,
        );
    }

    // =====================================================================
    // Numbers
    // =====================================================================

    /**
     * How big this shop actually is.
     *
     * ⚠️ **Not pass-or-fail, and not pretended to be.** Two hundred thousand
     * movements is not a fault; it is the answer to "why has this got slow",
     * which is the first question anybody asks. It renders as a plain table
     * with no tick beside it.
     *
     * @return array<string, string>
     */
    public function numbers(): array
    {
        $rows = [
            __('Products') => Product::count(),
            __('Customers') => Customer::count(),
            __('Suppliers') => Supplier::count(),
            __('Invoices') => Sale::count(),
            __('Purchases') => Purchase::count(),
            __('Stock batches') => StockBatch::count(),
            __('Stock movements') => StockMovement::count(),
        ];

        if (Schema::hasTable('activity_logs')) {
            $rows[__('History entries')] = DB::table('activity_logs')->count();
        }

        return array_map(fn ($n) => number_format((int) $n), $rows);
    }

    // =====================================================================

    private function titleFor(string $key): string
    {
        return match ($key) {
            'licence_state' => __('The licence is good and not about to lapse'),
            'disk_space' => __('There is disk left to write to'),
            'writable' => __('The shop can write to its own folders'),
            'backup_age' => __('A backup has run recently'),
            'log_size' => __('The error log is not running away'),
            'migrations' => __('Every migration has been run'),
            'assets' => __('The compiled styles and scripts are in place'),
            'php' => __('PHP is a version this shop supports'),
            default => $key,
        };
    }

    /**
     * @param  list<array<string, string>>  $examples
     * @return array<string, mixed>
     */
    private function verdict(
        string $key,
        string $title,
        string $because,
        string $severity,
        array $examples = [],
        ?string $repair = null,
        ?string $note = null,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'because' => $because,
            'severity' => $severity,
            'examples' => $examples,
            'repair' => $repair,
            'note' => $note,
        ];
    }
}
