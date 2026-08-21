<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Section 2: the interface must work in English, Kurdish Sorani, Arabic and
 * Persian. English is the source language, so it needs no file — the other
 * three do, and this reports what is missing from each.
 *
 *   php artisan translations:check          — report coverage
 *   php artisan translations:check --sync   — add missing keys with empty values
 */
class TranslationsCheck extends Command
{
    protected $signature = 'translations:check
                            {--sync : Add any missing keys to each language file with an empty value}';

    protected $description = 'Report which interface strings are still untranslated';

    /** English is the source, so it has no file of its own. */
    private const LANGUAGES = ['ckb', 'ar', 'fa'];

    private const SCAN = ['app', 'resources/views'];

    public function handle(): int
    {
        $strings = $this->extract();

        $this->info(sprintf('Found %d translatable strings.', count($strings)));
        $this->newLine();

        $exit = self::SUCCESS;

        foreach (self::LANGUAGES as $language) {
            $path = lang_path("{$language}.json");

            $existing = file_exists($path)
                ? (array) json_decode(file_get_contents($path), true)
                : [];

            $missing = array_values(array_diff($strings, array_keys($existing)));
            $untranslated = array_keys(array_filter($existing, fn ($value) => $value === ''));
            $stale = array_values(array_diff(array_keys($existing), $strings));

            // A value identical to the English is not necessarily a gap: "SKU",
            // "FIFO" and "…" are meant to stay as they are. Reported separately
            // so a real oversight is still visible without crying wolf.
            $identical = array_keys(array_filter(
                $existing,
                fn ($value, $key) => $value !== '' && $value === $key,
                ARRAY_FILTER_USE_BOTH
            ));

            $done = count($strings) - count($missing) - count($untranslated);
            $percent = count($strings) > 0 ? round($done / count($strings) * 100) : 100;

            $this->line(sprintf(
                '<comment>%s</comment>  %d%% translated  ·  %d missing  ·  %d empty  ·  %d kept as English  ·  %d no longer used',
                $language,
                $percent,
                count($missing),
                count($untranslated),
                count($identical),
                count($stale),
            ));

            if ($missing !== [] || $untranslated !== []) {
                $exit = self::FAILURE;
            }

            if ($this->option('sync') && $missing !== []) {
                foreach ($missing as $key) {
                    $existing[$key] = '';
                }

                ksort($existing, SORT_NATURAL | SORT_FLAG_CASE);

                file_put_contents(
                    $path,
                    json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
                );

                $this->line(sprintf('   added %d empty keys to %s', count($missing), $path));
            }

            if ($this->output->isVerbose() && $missing !== []) {
                foreach ($missing as $key) {
                    $this->line('   missing: '.$key);
                }
            }
        }

        return $exit;
    }

    /**
     * Pull every __('…') literal out of the codebase.
     *
     * Only literals: a translation key built at runtime cannot be collected,
     * which is one more reason machine values render through Str::headline()
     * rather than being passed to __().
     *
     * @return list<string>
     */
    private function extract(): array
    {
        $found = [];

        foreach (self::SCAN as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = file_get_contents($file->getPathname());

                // Both __() and trans_choice(): a pluralised string is just as
                // much a piece of interface text, and missing it here would let
                // "3 products moved" ship untranslated with nothing to warn us.
                preg_match_all("/(?:__|trans_choice)\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $single);
                preg_match_all('/(?:__|trans_choice)\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $code, $double);

                foreach (array_merge($single[1], $double[1]) as $match) {
                    $key = stripcslashes($match);

                    // Dot notation means a PHP lang file — __('auth.password')
                    // resolves from lang/{locale}/auth.php, not from the JSON,
                    // so collecting it here would create a key that never matches.
                    if (preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/i', $key)) {
                        continue;
                    }

                    $found[$key] = true;
                }
            }
        }

        $keys = array_keys($found);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return $keys;
    }
}
