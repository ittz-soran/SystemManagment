<?php

namespace Tests\Feature;

use App\Models\StockBatch;
use App\Models\StockMovement;
use Tests\TestCase;

/**
 * Every `unit_cost` multiplication is signed.
 *
 * `unit_cost` is an UNSIGNED BIGINT and `quantity` is signed — a movement out of
 * the shop is negative. MySQL promotes a signed-by-unsigned product to unsigned,
 * so the moment a negative quantity meets a cost the expression wraps and the
 * query dies with **1690 BIGINT UNSIGNED value is out of range**. SQLite has no
 * unsigned types at all.
 *
 * That is the trap: the same SQL passes every test here and takes the shop down
 * in the morning. It already happened once — the reports page and the dashboard
 * both 500'd on Soran's server while 767 tests were green — and it is the second
 * fault of this shape after `as lines` (reserved in MariaDB, ordinary in SQLite).
 *
 * So this test does not run the SQL. It reads the source, which is the only way
 * to catch an engine difference from a suite that runs on the other engine.
 */
class UnsignedCostTest extends TestCase
{
    /** Every file that hands raw SQL to the database. */
    private function sources(): array
    {
        $files = [];

        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($directory as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function test_no_raw_sql_multiplies_an_unsigned_cost_without_casting_it(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            // Read only the string literals: a comment explaining the rule, or
            // this test's own name for it, is not a query.
            foreach (token_get_all($code) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $sql = $token[1];

                if (! str_contains($sql, 'unit_cost')) {
                    continue;
                }

                // A multiplication involving unit_cost has to carry the cast.
                if (preg_match('/\*\s*unit_cost|unit_cost\s*\*/', $sql)
                    && ! str_contains($sql, 'CAST(unit_cost AS SIGNED)')) {
                    $offenders[] = basename($path).': '.trim($sql, "'\"");
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Raw SQL multiplies unit_cost without casting it to SIGNED.',
            'unit_cost is UNSIGNED BIGINT: in MySQL a negative quantity times it',
            'wraps and the query dies with error 1690. SQLite will not tell you.',
            'Use StockMovement::VALUE or StockBatch::VALUE instead.',
        ]));
    }

    public function test_the_named_expressions_are_the_ones_everything_should_use(): void
    {
        // If either of these loses its cast, the rule above passes and every
        // caller breaks at once — so the constants are pinned too.
        foreach ([StockMovement::VALUE, StockBatch::VALUE, StockBatch::PAID] as $expression) {
            $this->assertStringContainsString('CAST(unit_cost AS SIGNED)', $expression);
        }

        $this->assertStringStartsWith('quantity *', StockMovement::VALUE);
        $this->assertStringStartsWith('quantity_remaining *', StockBatch::VALUE);
        $this->assertStringStartsWith('quantity_in *', StockBatch::PAID);
    }

    public function test_the_cast_is_arithmetic_rather_than_decoration(): void
    {
        // Proved against real MariaDB when this was fixed; asserted here so the
        // expression stays an expression the database can actually evaluate.
        $signed = \DB::selectOne('select '.str_replace(
            ['quantity', 'unit_cost'], ['-4', '6000'], StockMovement::VALUE
        ).' as value');

        $this->assertSame(-24000, (int) $signed->value);
    }
}
