<?php

namespace App\Console\Commands;

use App\Services\Licence;
use App\Services\SchemaVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Everything about one shop, in one output.
 *
 * Written after six rounds of me mis-diagnosing Soran's server from partial
 * output. Each round cost him a day and cost me a wrong answer: the assets
 * were untracked when they were deleted, the build was missing when it was
 * present, the database was behind when it was not. Every one of those would
 * have been settled in a glance by the facts below, and none of them was
 * reachable without asking him to run something new.
 *
 * So this asks everything at once, and it is deliberately dumb — it reports
 * and it does not fix. A command that repaired what it found would have
 * destroyed the evidence of the thing I still cannot see.
 *
 *     php /home/soransto/shops/bazaar/artisan shop:doctor
 *     php /home/soransto/shops/bazaar/artisan shop:doctor --json
 *
 * The last section is the one that matters most and was hardest to get: the
 * errors this shop has actually recorded. The log lives under the shop's own
 * storage folder, not the shared codebase's, which is why nobody found it.
 */
class ShopDoctor extends Command
{
    protected $signature = 'shop:doctor
                            {--json : Print the report as JSON, for the panel}
                            {--errors=8 : How many recent errors to show}';

    protected $description = 'Report everything about this shop: folders, schema, assets, licence and its recent errors';

    public function handle(): int
    {
        $report = [
            'shop' => $this->shop(),
            'database' => $this->database(),
            'drivers' => $this->drivers(),
            'assets' => $this->assets(),
            'licence' => $this->licence(),
            'errors' => $this->errors((int) $this->option('errors')),
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($report as $section => $values) {
            $this->newLine();
            $this->components->info(str_replace('_', ' ', ucfirst($section)));

            if ($section === 'errors') {
                $values === []
                    ? $this->line('  nothing recorded')
                    : array_map(fn ($line) => $this->line('  '.$line), $values);

                continue;
            }

            foreach ($values as $key => $value) {
                $this->line(sprintf('  %-22s %s', $key, $this->readable($value)));
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => $value === [] ? '—' : implode(', ', $value),
            $value === null => '—',
            default => (string) $value,
        };
    }

    /** @return array<string, mixed> */
    private function shop(): array
    {
        $home = defined('SHOP_HOME') ? rtrim((string) constant('SHOP_HOME'), '/\\') : null;

        return [
            'is a shop' => $home !== null,
            'home' => $home ?? '(this is the shared codebase)',
            'public' => rtrim(defined('SHOP_PUBLIC') ? (string) constant('SHOP_PUBLIC') : public_path(), '/\\'),
            // The one that has bitten twice: a shop whose entry point predates
            // SHOP_PUBLIC writes its assets into a private folder nobody serves.
            'public came from' => defined('SHOP_PUBLIC') ? 'SHOP_PUBLIC' : 'defaulted to <home>/public',
            'shared codebase' => base_path(),
            'code version' => $this->gitDescription(),
            'app url' => config('app.url'),
        ];
    }

    private function gitDescription(): string
    {
        $head = base_path('.git/HEAD');

        if (! is_file($head)) {
            return 'not a git checkout';
        }

        $contents = trim((string) file_get_contents($head));

        if (str_starts_with($contents, 'ref: ')) {
            $ref = substr($contents, 5);
            $sha = @file_get_contents(base_path('.git/'.$ref));

            return basename($ref).' '.substr(trim((string) $sha), 0, 8);
        }

        return substr($contents, 0, 8);
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return ['reachable' => false, 'why' => $e->getMessage()];
        }

        $pending = app(SchemaVersion::class)->pending();

        return [
            'reachable' => true,
            'name' => DB::connection()->getDatabaseName(),
            'driver' => DB::connection()->getDriverName(),
            'migrations pending' => $pending,
            'up to date' => $pending === 0,
            // Named because a write to a column the database has not got is
            // the exact shape of the 500s Soran kept hitting.
            'users columns' => $this->missingUserColumns(),
        ];
    }

    /**
     * Columns the code writes to that this database has not got.
     *
     * @return array<string>|string
     */
    private function missingUserColumns(): array|string
    {
        try {
            if (! Schema::hasTable('users')) {
                // Otherwise every column is reported missing, which reads as a
                // catastrophe when it is really a database nobody has migrated.
                return 'there is no users table — this shop has never been migrated';
            }

            $has = Schema::getColumnListing('users');
        } catch (Throwable) {
            return 'could not be read';
        }

        $expected = [
            'language', 'theme', 'items_per_page', 'is_active', 'role',
            'cost_visibility', 'cost_markup_percent',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'date_language', 'clock_24_hour',
        ];

        $missing = array_values(array_diff($expected, $has));

        return $missing === [] ? ['none missing'] : $missing;
    }

    /**
     * The drivers, and whether what they need actually exists.
     *
     * Laravel's defaults for both are `database`, which is a table that has to
     * have been migrated — and an install whose .env names neither is using
     * them without anybody having decided to.
     *
     * @return array<string, mixed>
     */
    private function drivers(): array
    {
        $session = config('session.driver');
        $cache = config('cache.default');

        $tableFor = function (string $driver, string $table): string {
            if ($driver !== 'database') {
                return 'not needed';
            }

            try {
                return Schema::hasTable($table) ? 'present' : 'MISSING';
            } catch (Throwable) {
                return 'could not be read';
            }
        };

        return [
            'environment' => app()->environment(),
            'debug' => config('app.debug'),
            'session driver' => $session,
            'sessions table' => $tableFor($session, 'sessions'),
            'cache store' => $cache,
            'cache table' => $tableFor($cache, config('cache.stores.database.table', 'cache')),
            'storage writable' => is_writable(storage_path('logs')),
        ];
    }

    /**
     * What the browser will be asked to load, and whether it is there.
     *
     * @return array<string, mixed>
     */
    private function assets(): array
    {
        $public = rtrim(defined('SHOP_PUBLIC') ? (string) constant('SHOP_PUBLIC') : public_path(), '/\\');

        $shared = base_path('public/build/manifest.json');
        $theirs = $public.'/build/manifest.json';

        $report = [
            'shared manifest' => is_file($shared) ? substr(hash_file('sha256', $shared), 0, 12) : 'MISSING',
            'shop manifest' => is_file($theirs) ? substr(hash_file('sha256', $theirs), 0, 12) : 'MISSING',
        ];

        $report['they match'] = is_file($shared) && is_file($theirs)
            && hash_file('sha256', $shared) === hash_file('sha256', $theirs);

        // The actual file the page will link to. Present-and-matching manifests
        // still serve nothing if the stylesheet beside them never arrived.
        if (is_file($theirs)) {
            $manifest = json_decode((string) file_get_contents($theirs), true);
            $css = $manifest['resources/scss/app.scss']['file'] ?? null;

            $report['stylesheet'] = $css ?? 'not named in the manifest';
            $report['stylesheet is there'] = $css !== null && is_file($public.'/build/'.$css);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function licence(): array
    {
        try {
            $licence = app(Licence::class);

            return ['required' => $licence->isRequired(), 'state' => $licence->state()];
        } catch (Throwable $e) {
            return ['state' => 'could not be read', 'why' => $e->getMessage()];
        }
    }

    /**
     * What this shop has actually gone wrong with, most recent first.
     *
     * The log is under the shop's own storage folder rather than the shared
     * codebase's, which is exactly why it went unread for days. One line each:
     * the message, not the stack trace, because the message is the part that
     * names the fault.
     *
     * @return list<string>
     */
    private function errors(int $limit): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return [];
        }

        // Only the tail: a shop that has been trading for months has a log
        // nobody wants read into memory.
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ['the log could not be opened'];
        }

        fseek($handle, max(0, filesize($path) - 256_000));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $found = [];

        foreach (explode("\n", $tail) as $line) {
            if (preg_match('/^\[[\d\-: ]+\]\s+\w+\.(ERROR|CRITICAL|EMERGENCY):\s*(.*)$/', $line, $m)) {
                $found[] = mb_substr(trim($m[2]), 0, 300);
            }
        }

        return array_slice(array_reverse($found), 0, max(1, $limit));
    }
}
