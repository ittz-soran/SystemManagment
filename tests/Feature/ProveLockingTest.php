<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
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

    /** A pass on SQLite would be a lie, so it will not offer one. */
    public function test_it_refuses_to_pretend_on_a_driver_without_row_locks(): void
    {
        Config::set('database.connections.mysql.driver', 'sqlite');

        $this->artisan('stock:prove-locking', ['--database' => 'scratch'])
            ->expectsOutputToContain('proves nothing on sqlite')
            ->assertExitCode(1);
    }
}
