<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Raw SQL that MariaDB will accept.
 *
 * **Written 2026-09-16, after `as lines`.**
 *
 * The rooms page counted distinct products into an alias called `lines`. LINES
 * is a RESERVED WORD in MariaDB — it belongs to `LOAD DATA … LINES TERMINATED
 * BY` — so unquoted it is a syntax error there, and completely ordinary in
 * SQLite:
 *
 *     SQLSTATE[42000]: 1064 …check the manual… near 'lines from
 *     `stock_batches` where `quantity_remaining` > ? group by `room_id`'
 *
 * ⚠️ **The suite runs on SQLite, so nothing local could catch it.** 1064 tests
 * passed, four languages were complete, Pint was clean, and the MariaDB job in
 * CI went red on its own. That is the whole hazard: the shop RUNS on MariaDB,
 * the tests do not, and the gap is only ever found by pushing.
 *
 * So this closes it statically. It reads the raw SQL out of the source and
 * fails on an alias MariaDB would refuse — no database required, which is the
 * point, because there is no MariaDB on the machine where this is written.
 *
 * The same trap is recorded twice elsewhere: DataIntegrityService survives a
 * check that will not run because of "a word that is reserved on MariaDB and
 * ordinary on SQLite", and StockMovement::VALUE exists because unsigned
 * arithmetic wraps on MySQL and does not in SQLite.
 */
class SqlPortabilityTest extends TestCase
{
    /**
     * MariaDB 10.11 reserved words.
     *
     * Reserved ones only: a non-reserved word here would fail a build for no
     * reason, which is how a guard gets deleted rather than obeyed.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'accessible', 'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc', 'before', 'between',
        'bigint', 'binary', 'blob', 'both', 'by', 'call', 'cascade', 'case', 'change', 'char',
        'character', 'check', 'collate', 'column', 'condition', 'constraint', 'continue', 'convert',
        'create', 'cross', 'cursor', 'database', 'databases', 'dec', 'decimal', 'declare', 'default',
        'delayed', 'delete', 'desc', 'describe', 'deterministic', 'distinct', 'distinctrow', 'div',
        'double', 'drop', 'dual', 'each', 'else', 'elseif', 'enclosed', 'escaped', 'except', 'exists',
        'exit', 'explain', 'false', 'fetch', 'float', 'for', 'force', 'foreign', 'from', 'fulltext',
        'general', 'grant', 'group', 'having', 'if', 'ignore', 'in', 'index', 'infile', 'inner',
        'inout', 'insensitive', 'insert', 'int', 'integer', 'intersect', 'interval', 'into', 'is',
        'iterate', 'join', 'key', 'keys', 'kill', 'leading', 'leave', 'left', 'like', 'limit',
        'linear', 'lines', 'load', 'lock', 'long', 'longblob', 'longtext', 'loop', 'match', 'maxvalue',
        'mediumblob', 'mediumint', 'mediumtext', 'mod', 'modifies', 'natural', 'not', 'null',
        'numeric', 'offset', 'on', 'optimize', 'option', 'optionally', 'or', 'order', 'out', 'outer',
        'outfile', 'over', 'partition', 'position', 'precision', 'primary', 'procedure', 'purge',
        'range', 'read', 'reads', 'real', 'recursive', 'references', 'regexp', 'release', 'rename',
        'repeat', 'replace', 'require', 'resignal', 'restrict', 'return', 'returning', 'revoke',
        'right', 'rlike', 'rows', 'schema', 'schemas', 'select', 'sensitive', 'separator', 'set',
        'show', 'signal', 'slow', 'smallint', 'spatial', 'specific', 'sql', 'ssl', 'starting',
        'straight_join', 'table', 'terminated', 'then', 'tinyblob', 'tinyint', 'tinytext', 'to',
        'trailing', 'trigger', 'true', 'undo', 'union', 'unique', 'unlock', 'unsigned', 'update',
        'usage', 'use', 'using', 'values', 'varbinary', 'varchar', 'varcharacter', 'varying', 'when',
        'where', 'while', 'window', 'with', 'write', 'xor', 'zerofill',
    ];

    public function test_no_raw_sql_names_a_column_mariadb_has_reserved(): void
    {
        $offences = [];

        foreach ($this->rawSql() as [$file, $sql]) {
            // `… as alias` — the shape that broke. A quoted alias is fine, so
            // only bare words are looked at.
            preg_match_all('/\bas\s+([a-z_][a-z0-9_]*)/i', $sql, $matches);

            foreach ($matches[1] as $alias) {
                if (in_array(strtolower($alias), self::RESERVED, true)) {
                    $offences[] = "{$file}: `as {$alias}` — {$alias} is reserved in MariaDB";
                }
            }
        }

        sort($offences);

        $this->assertSame([], $offences, implode("\n", [
            'These raw-SQL aliases are reserved words in MariaDB. They work in',
            'SQLite, so the suite passes and the MariaDB job in CI goes red.',
            'Rename the alias — `as lines` became `as products_held`.',
            '',
            ...$offences,
            '',
        ]));
    }

    /**
     * Every raw SQL fragment in the application, with the file it is in.
     *
     * Only the arguments of the `*Raw()` builders and `DB::raw()`, so an
     * English sentence containing the word "as" is never mistaken for SQL.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rawSql(): array
    {
        $found = [];

        foreach ([app_path(), base_path('database')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                preg_match_all(
                    "/(?:selectRaw|whereRaw|havingRaw|orderByRaw|groupByRaw|joinRaw|DB::raw|raw)\(\s*'([^']*)'/",
                    $source,
                    $matches,
                );

                foreach ($matches[1] as $sql) {
                    $found[] = [str_replace(base_path().'/', '', $file->getPathname()), $sql];
                }
            }
        }

        return $found;
    }

    /** The guard is worth nothing if it is not reading anything. */
    public function test_the_guard_actually_finds_the_raw_sql(): void
    {
        $sql = $this->rawSql();

        $this->assertGreaterThan(
            10,
            count($sql),
            'The scan found almost no raw SQL, so it is passing by looking at nothing.'
        );

        // And that it reaches the file the failure came from.
        $this->assertStringContainsString(
            'products_held',
            implode(' ', array_column($sql, 1)),
            'The scan is not reading StockRoomController, which is where this went wrong.'
        );
    }

    /**
     * ⚠️ **`->after('x')` must name a column that table actually has.**
     *
     * The third fault of this exact shape, and the most expensive: SQLite
     * IGNORES `after()` completely, so a wrong column name passes every test
     * on the machine this is written on. MariaDB enforces it, throws on the
     * ALTER, and takes the whole migration down with it — 997 tests failed on
     * one word.
     *
     * 2026-09-19: `sales.exchange_rate` was added `after('grand_total')`,
     * copied from the purchase side. A sale has no grand_total; it has
     * total_amount.
     *
     * No database needed, which is the point — the same reason the reserved
     * word scan above exists.
     */
    public function test_every_after_names_a_column_that_table_has(): void
    {
        /** @var array<string, list<string>> $have */
        $have = [];
        $problems = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $source = file_get_contents($path);

            foreach ($this->schemaBlocks($source) as [$table, $block]) {
                $known = $have[$table] ?? [];

                // Columns this block itself adds count: a migration may add two
                // and place the second after the first.
                $adds = $this->columnsIn($block);

                preg_match_all("/->after\(\s*'([a-z_]+)'/", $block, $afters);

                foreach ($afters[1] as $column) {
                    if (! in_array($column, $known, true) && ! in_array($column, $adds, true)) {
                        $problems[] = basename($path).": {$table}.{$column}";
                    }
                }

                $have[$table] = array_merge($known, $adds);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "A migration places a column after one that table does not have. SQLite ignores this; MariaDB refuses it:\n".implode("\n", $problems)
        );
    }

    /** The scan has to be reading something, or it passes forever proving nothing. */
    public function test_the_after_guard_is_reading_the_migrations(): void
    {
        $seen = 0;

        foreach (glob(database_path('migrations/*.php')) as $path) {
            foreach ($this->schemaBlocks(file_get_contents($path)) as [, $block]) {
                $seen += preg_match_all("/->after\(\s*'[a-z_]+'/", $block);
            }
        }

        $this->assertGreaterThan(5, $seen, 'The after() scan found almost nothing to check.');
    }

    /**
     * Every `Schema::create`/`Schema::table` call, captured to its balanced
     * closing bracket.
     *
     * ⚠️ Balanced, not a fixed window. A file holding two of these — and
     * several do — otherwise bleeds one table's columns into the next and
     * reports faults that are not there.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function schemaBlocks(string $source): array
    {
        preg_match_all("/Schema::(?:create|table)\(\s*'([a-z_]+)'/", $source, $matches, PREG_OFFSET_CAPTURE);

        $out = [];

        foreach ($matches[0] as $i => [$text, $offset]) {
            $open = strpos($source, '(', $offset);
            $depth = 0;

            for ($j = $open; $j < strlen($source); $j++) {
                if ($source[$j] === '(') {
                    $depth++;
                } elseif ($source[$j] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }
            }

            $out[] = [$matches[1][$i][0], substr($source, $open, $j - $open)];
        }

        return $out;
    }

    /**
     * The columns one block defines.
     *
     * @return list<string>
     */
    private function columnsIn(string $block): array
    {
        preg_match_all("/->([a-zA-Z]+)\(\s*'([a-z_]+)'/", $block, $matches, PREG_SET_ORDER);

        $skip = ['after', 'index', 'unique', 'constrained', 'references', 'on',
            'default', 'comment', 'dropColumn', 'dropIndex', 'dropUnique'];

        $columns = [];

        foreach ($matches as $match) {
            if (! in_array($match[1], $skip, true)) {
                $columns[] = $match[2];
            }
        }

        // The ones whose names are implied rather than typed.
        foreach (['timestamps' => ['created_at', 'updated_at'], 'softDeletes' => ['deleted_at'],
            'rememberToken' => ['remember_token'], 'id' => ['id']] as $method => $implied) {
            if (preg_match('/->'.$method.'\(\s*\)/', $block)) {
                $columns = array_merge($columns, $implied);
            }
        }

        return $columns;
    }
}
