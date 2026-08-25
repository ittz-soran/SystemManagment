<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guards on the locking prover.
 *
 * The command rebuilds whatever database it is handed, from nothing, because
 * proving a lock needs real committed transactions and there is no way to do
 * that inside something that can be rolled back. So what it refuses to do
 * matters more than what it does, and that part is worth a test even though
 * the proof itself needs a MySQL server this suite does not have.
 */
class ProveLockingTest extends TestCase
{
    /**
     * The command repoints the default connection at the scratch database, in
     * this very process. Left that way, the suite's own teardown would go
     * looking for tables down a connection that no longer answers.
     */
    protected function tearDown(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge();

        parent::tearDown();
    }

    public function test_it_refuses_to_run_without_a_database_of_its_own(): void
    {
        $this->artisan('stock:prove-locking')
            ->expectsOutputToContain('This needs a database of its own')
            ->assertExitCode(1);
    }

    /** The one mistake that would cost a shop its books. */
    public function test_it_refuses_the_shops_own_database(): void
    {
        Config::set('database.connections.mysql.database', 'store_management');

        $this->artisan('stock:prove-locking', ['--database' => 'store_management'])
            ->expectsOutputToContain('the shop\'s own database')
            ->assertExitCode(1);
    }

    /**
     * A racer that never got as far as trying must say so, out loud.
     *
     * This is the bug the first version of this command had: it caught every
     * exception the same way, so a racer that crashed on startup looked exactly
     * like a racer the lock had correctly refused. Nothing sold, and the tool
     * announced that the lock had failed and named the wrong culprit. A tool
     * that reports a healthy lock as broken will one day report a broken one as
     * healthy, and that one costs a shop its books.
     */
    public function test_a_racer_that_cannot_even_connect_says_so_rather_than_looking_refused(): void
    {
        Config::set('database.connections.mysql.host', '127.0.0.1');
        Config::set('database.connections.mysql.port', '1');   // nothing listens here

        $this->artisan('stock:prove-locking', [
            '--child' => true,
            '--database' => 'scratch',
            '--product' => 1,
            '--want' => 4,
            '--at' => microtime(true),
        ])
            ->expectsOutputToContain('broke:')
            ->assertExitCode(1);
    }

    /** A pass on SQLite would be a lie, so it will not offer one. */
    public function test_it_refuses_to_pretend_on_a_driver_without_row_locks(): void
    {
        Config::set('database.connections.mysql.driver', 'sqlite');

        $this->artisan('stock:prove-locking', ['--database' => 'scratch'])
            ->expectsOutputToContain('proves nothing on sqlite')
            ->assertExitCode(1);
    }
}
