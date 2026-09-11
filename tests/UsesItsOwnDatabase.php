<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * For a test that genuinely destroys the database it runs against.
 *
 * Three of them do, and they are right to: restoring a backup means dropping
 * every table and putting them back, and proving the batch lock means committing
 * real transactions that a rollback would hide. There is no honest way to test
 * either inside something that can be undone.
 *
 * On SQLite that costs nothing — the test database is `:memory:`, a private
 * throwaway belonging to one connection, so a test can demolish it and the next
 * one gets a fresh one regardless.
 *
 * **On MySQL it is a catastrophe, and this trait exists because it was one.**
 * The test database there is a real shared schema, and DDL cannot be rolled
 * back: a `DROP TABLE` commits the transaction `RefreshDatabase` was relying on
 * and takes the schema with it. One such test near the front of the run left
 * 223 later tests failing with *"table doesn't exist"* — failures that had
 * nothing to do with the code under test and pointed nowhere near the culprit.
 *
 * So a test that wants to destroy a database gets one of its own to destroy.
 * It is created, migrated, seeded, wrecked and dropped, and the shared schema
 * every other test relies on is never touched.
 *
 * A class using this must NOT also use `RefreshDatabase`: that wraps the test in
 * a transaction on the connection this trait is about to point somewhere else,
 * and the rollback would then have nothing to roll back.
 */
trait UsesItsOwnDatabase
{
    private ?string $ownDatabase = null;

    private ?string $sharedDatabase = null;

    /**
     * Called by Laravel's `setUpTraits()`, by name.
     */
    protected function setUpUsesItsOwnDatabase(): void
    {
        $connection = config('database.default');

        $this->sharedDatabase = config("database.connections.{$connection}.database");

        // SQLite's `:memory:` is already private to this connection, and a file
        // database is a file this test may have. Nothing to protect anyone from.
        if (! $this->onARealServer()) {
            $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);

            return;
        }

        // Short and random rather than derived from the class name: a run that
        // dies half way leaves the scratch behind, and a fixed name would then
        // collide with the next run rather than simply being litter.
        $this->ownDatabase = 'scratch_'.Str::lower(Str::random(16));

        DB::statement("CREATE DATABASE `{$this->ownDatabase}` CHARACTER SET utf8mb4");

        $this->pointAt($this->ownDatabase);

        $this->artisan('migrate', ['--seed' => true, '--force' => true]);
    }

    protected function tearDownUsesItsOwnDatabase(): void
    {
        if ($this->ownDatabase === null) {
            return;
        }

        // Back to the shared schema first: the scratch cannot be dropped from a
        // connection that is sitting inside it.
        $scratch = $this->ownDatabase;
        $this->ownDatabase = null;

        $this->pointAt($this->sharedDatabase);

        DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
    }

    /** Whether the database is one other tests are sharing. */
    private function onARealServer(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function pointAt(?string $database): void
    {
        $connection = config('database.default');

        config(["database.connections.{$connection}.database" => $database]);

        DB::purge($connection);
        DB::reconnect($connection);
    }
}
