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
}
