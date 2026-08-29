<?php

namespace Tests;

use App\Models\Customer;
use App\Models\StockBatch;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * One suite writes a corrupt row on purpose.
     *
     * DataCheckTest exists to prove the data check notices corruption, which it
     * can only do by causing some. Every other test keeps the guard below.
     */
    protected bool $breaksInvariantsOnPurpose = false;

    /**
     * The schema makes stock_batches.quantity_remaining and both balance columns
     * UNSIGNED so the database itself refuses a corrupt value — but only MySQL
     * honours that. SQLite stores -5 in an UNSIGNED column without complaint, so
     * the suite, which runs on SQLite, gets none of that protection.
     *
     * This asserts the same invariants after every single test instead. Without
     * it a bug that MySQL would reject outright passes silently here, which is
     * exactly how the sale-return delete bug reached production.
     */
    protected function tearDown(): void
    {
        if ($this->app && ! $this->breaksInvariantsOnPurpose && Schema::hasTable('stock_batches')) {
            $this->assertNoCorruptStock();
        }

        parent::tearDown();
    }

    private function assertNoCorruptStock(): void
    {
        $negativeBatches = StockBatch::where('quantity_remaining', '<', 0)->get();

        $this->assertCount(
            0,
            $negativeBatches,
            'A stock batch went negative: '.$negativeBatches
                ->map(fn ($b) => "#{$b->id} = {$b->quantity_remaining}")
                ->implode(', ')
                .'. MySQL would have rejected this outright; SQLite does not.'
        );

        $overfilled = StockBatch::whereColumn('quantity_remaining', '>', 'quantity_in')->get();

        $this->assertCount(
            0,
            $overfilled,
            'A stock batch exceeded its quantity_in: '.$overfilled
                ->map(fn ($b) => "#{$b->id} = {$b->quantity_remaining}/{$b->quantity_in}")
                ->implode(', ')
                .'. This is the corruption Section 10b T4 is designed to catch.'
        );

        if (Schema::hasTable('customers')) {
            $this->assertSame(
                0,
                Customer::where('balance', '<', 0)->count(),
                'A customer balance went negative; Section 4 forbids it.'
            );
        }

        if (Schema::hasTable('suppliers')) {
            $this->assertSame(
                0,
                Supplier::where('balance', '<', 0)->count(),
                'A supplier balance went negative; Section 4 forbids it.'
            );
        }
    }
}
