<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Section 5 (Concurrency): prove the batch lock on the shop's own database.
 *
 * There is a test for this — ConcurrencyTest — and on the shop's machine it can
 * never run. It forks with pcntl, which does not exist on Windows at all, and
 * the suite runs on SQLite, where lockForUpdate() is a silent no-op. So every
 * green run the shopkeeper has ever seen proved nothing whatsoever about the
 * one thing that quietly corrupts a ledger: two tills selling the last item at
 * the same moment, both reading "5 available", both taking 4, stock ending at
 * minus three with FIFO in pieces.
 *
 * This command proves it where it matters and on the machine it matters on. It
 * starts real, separate PHP processes rather than forking, which Windows can
 * do, and it hands each of them the same wall-clock instant to strike at, so
 * they genuinely collide rather than politely queueing.
 *
 * It will not touch the shop's data. It insists on a database of its own and
 * rebuilds it from nothing, because proving this needs real committed
 * transactions — there is no way to do it inside something that can be rolled
 * back afterwards.
 */
class ProveLocking extends Command
{
    protected $signature = 'stock:prove-locking
                            {--database= : An EMPTY database to use. Never the shop\'s own.}
                            {--racers=2 : How many tills to race}
                            {--stock=5 : Units put on the shelf}
                            {--want=4 : Units each till tries to take}
                            {--child : Internal. One racer, started by the run above.}
                            {--product= : Internal.}
                            {--at= : Internal. The instant to strike, as a unix timestamp.}';

    protected $description = 'Prove that two tills cannot oversell the same item on this machine\'s database';

    public function handle(): int
    {
        return $this->option('child') ? $this->race() : $this->prove();
    }

    // ---- The racer ------------------------------------------------------

    /**
     * One till, trying to take its units at the agreed instant.
     *
     * Exit 0 means the sale went through, 1 means it was refused. Refused is a
     * correct answer here — better than correct, it is the whole point.
     */
    private function race(): int
    {
        $this->connect();

        // Every racer waits for the same instant, so they arrive together. Two
        // processes started one after another are half a second apart, which is
        // not a race at all — it is a queue, and a queue proves nothing.
        $at = (float) $this->option('at');

        while (microtime(true) < $at) {
            usleep(200);
        }

        try {
            app(SaleService::class)->create(
                customer: Customer::firstOrFail(),
                lines: [[
                    'product_id' => (int) $this->option('product'),
                    'quantity' => (int) $this->option('want'),
                    'unit_price' => 10_000,
                ]],
                user: User::where('email', 'admin@example.com')->firstOrFail(),
                saleDate: now(),
                amountPaid: 0,
            );

            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }

    // ---- The proof ------------------------------------------------------

    private function prove(): int
    {
        $database = (string) $this->option('database');

        if ($database === '') {
            $this->components->error('This needs a database of its own. Pass --database=');
            $this->line('  Make an empty one first, then:');
            $this->line('  <fg=cyan>php artisan stock:prove-locking --database=store_locktest</>');

            return self::FAILURE;
        }

        if ($database === config('database.connections.mysql.database')) {
            $this->components->error('That is the shop\'s own database. This rebuilds whatever it is given.');

            return self::FAILURE;
        }

        $driver = config('database.connections.mysql.driver');

        if ($driver !== 'mysql') {
            $this->components->error("This proves nothing on {$driver}.");
            $this->line('  lockForUpdate() is a silent no-op outside MySQL and MariaDB, so a pass');
            $this->line('  here would be a lie. Point .env at MySQL and run it again.');

            return self::FAILURE;
        }

        $this->connect();

        // Asked plainly, so a server that is not running says so in one line
        // rather than a page of stack trace.
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $host = config('database.connections.mysql.host');
            $port = config('database.connections.mysql.port');

            $this->components->error("Cannot reach MySQL at {$host}:{$port}.");
            $this->line('  Start it in the XAMPP control panel, and make sure the database');
            $this->line("  <fg=cyan>{$database}</> exists and is empty.");
            $this->newLine();
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $racers = max(2, (int) $this->option('racers'));
        $stock = max(1, (int) $this->option('stock'));
        $want = max(1, (int) $this->option('want'));

        $this->components->info("Racing {$racers} tills for {$stock} units, each wanting {$want}.");

        $product = $this->shelf($stock);

        // Far enough ahead that every racer has booted and is waiting on it.
        $at = microtime(true) + 3.0;
        $php = (new PhpExecutableFinder)->find() ?: 'php';

        $processes = [];

        foreach (range(1, $racers) as $i) {
            $processes[] = tap(new Process([
                $php, 'artisan', 'stock:prove-locking',
                '--child',
                '--database='.$database,
                '--product='.$product->id,
                '--want='.$want,
                '--at='.$at,
            ], base_path(), null, null, 60))->start();
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $won = count(array_filter($processes, fn (Process $p) => $p->getExitCode() === 0));

        return $this->verdict($product, $won, $stock, $want);
    }

    /** One product, one batch, a known number of units. */
    private function shelf(int $stock): Product
    {
        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $product = Product::create([
            'name' => 'Contested item',
            'sku' => 'LOCK-1',
            'category_id' => Category::firstOrCreate(['name' => 'Test'])->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 10_000,
            'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Test supplier']),
            lines: [['product_id' => $product->id, 'quantity' => $stock, 'unit_price' => 1_000]],
            user: User::where('email', 'admin@example.com')->firstOrFail(),
            purchaseDate: now(),
        );

        Customer::firstOrCreate(['name' => 'Test customer']);

        return $product;
    }

    private function verdict(Product $product, int $won, int $stock, int $want): int
    {
        DB::purge();
        $this->connect();

        $remaining = (int) StockBatch::where('product_id', $product->id)->sum('quantity_remaining');
        $cached = (int) Product::findOrFail($product->id)->quantity;

        // How many could honestly have won: 5 units and 4 wanted each means one.
        $allowed = intdiv($stock, $want);

        $this->newLine();
        $this->table(['What', 'Result', 'Should be'], [
            ['Sales that went through', $won, $allowed],
            ['Units left in the batches', $remaining, $stock - ($allowed * $want)],
            ['products.quantity cache', $cached, $remaining],
        ]);

        if ($won === $allowed && $remaining === $stock - ($allowed * $want) && $cached === $remaining) {
            $this->components->info('The lock holds. Two tills cannot oversell the same item on this database.');

            return self::SUCCESS;
        }

        $this->components->error('THE LOCK DID NOT HOLD. Do not go live on this database.');
        $this->line('  Stock went where it should not have. Check that the tables are InnoDB,');
        $this->line('  not MyISAM: MyISAM has no row locks and no transactions, and Laravel');
        $this->line('  will not tell you — it simply ignores both.');
        $this->newLine();
        $this->line('  <fg=cyan>SELECT table_name, engine FROM information_schema.tables</>');
        $this->line('  <fg=cyan>WHERE table_schema = DATABASE();</>');

        return self::FAILURE;
    }

    /** Point every connection at the scratch database, in this process. */
    private function connect(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => (string) $this->option('database'),
        ]);

        DB::purge('mysql');
    }
}
