<?php

namespace App\Console\Commands;

use App\Support\RecordHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

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

                // A Blade template's {{ __('…') }} sits in inline HTML, which
                // the tokeniser skips over, so compile it to PHP first.
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $code = Blade::compileString($code);
                }

                foreach ($this->keysIn($code) as $key) {
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

        foreach ($this->labelMaps() as $key) {
            $found[$key] = true;
        }

        $keys = array_keys($found);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return $keys;
    }

    /**
     * The one place a translation key is not written as a literal inside __().
     *
     * RecordHistory keeps a map of column name to the words the shop uses for
     * it, and passes the map's value to __() — so the tokeniser above sees
     * __($variable) and collects nothing. Four of those labels had quietly
     * shipped in English while this command reported 100%, which is worse than
     * reporting the gap. The map is a constant, so it can simply be read.
     *
     * @return list<string>
     */
    private function labelMaps(): array
    {
        return array_values(array_unique(
            (new ReflectionClass(RecordHistory::class))->getConstant('LABELS') ?: []
        ));
    }

    /**
     * Both __() and trans_choice(): a pluralised string is just as much a piece
     * of interface text, and missing it here would let "3 products moved" ship
     * untranslated with nothing to warn us.
     *
     * Tokenised rather than matched with a regex, because a long plural message
     * is normally written as two literals joined across lines:
     *
     *     trans_choice('{1}One thing.'
     *         .'|[2,*]:count things.', $n)
     *
     * A regex reads only the first piece, so the plural branch would silently
     * never reach the lang files and would fall back to English.
     *
     * @return list<string>
     */
    private function keysIn(string $code): array
    {
        $keys = [];
        $tokens = token_get_all($code);
        $count = count($tokens);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (! in_array(ltrim($token[1], '\\'), ['__', 'trans_choice'], true)) {
                continue;
            }

            $j = $i + 1;

            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
                $j++;
            }

            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            // Read the first argument: one literal, or several joined by ".".
            $parts = [];
            $expect = 'string';

            for ($j++; $j < $count; $j++) {
                $part = $tokens[$j];

                if (is_array($part) && in_array($part[0], $skip, true)) {
                    continue;
                }

                if ($expect === 'string' && is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $parts[] = $this->unquote($part[1]);
                    $expect = 'operator';

                    continue;
                }

                if ($expect === 'operator' && $part === '.') {
                    $expect = 'string';

                    continue;
                }

                break;
            }

            // $expect is still 'string' when the argument ended on a "." — the
            // rest is built at runtime, so there is no literal key to collect.
            if ($parts !== [] && $expect === 'operator') {
                $keys[] = implode('', $parts);
            }
        }

        return $keys;
    }

    /** Strip the quotes and undo the escapes PHP itself would have undone. */
    private function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? strtr($body, ["\\'" => "'", '\\\\' => '\\'])
            : stripcslashes($body);
    }
}
