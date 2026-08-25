<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientStockException;
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

    /** The sale went through. */
    private const SOLD = 0;

    /** Something went wrong that has nothing to do with stock. */
    private const BROKE = 1;

    /** Refused for want of stock — which is the correct answer for all but one. */
    private const REFUSED = 3;

    /**
     * One till, trying to take its units at the agreed instant.
     *
     * The three endings are kept apart on purpose. A racer that crashes and a
     * racer that is correctly refused both simply "did not sell", and a tool
     * that cannot tell them apart will one day report a healthy lock as broken,
     * or — far worse — a broken one as healthy.
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

            return self::SOLD;
        } catch (InsufficientStockException $e) {
            $this->line('refused: '.$e->getMessage());

            return self::REFUSED;
        } catch (Throwable $e) {
            $this->line('broke: '.$e::class.': '.$e->getMessage());

            return self::BROKE;
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

        $endings = array_map(fn (Process $p) => $p->getExitCode(), $processes);

        // A racer that never got as far as trying makes the whole run
        // meaningless, so it is reported as that and not as a verdict.
        $broken = array_filter($processes, fn (Process $p) => $p->getExitCode() !== self::SOLD
            && $p->getExitCode() !== self::REFUSED);

        if ($broken !== []) {
            $this->newLine();
            $this->components->error('The check could not run. This says nothing about the lock.');
            $this->line('  '.count($broken).' of '.$racers.' tills never got as far as trying. What they said:');
            $this->newLine();

            foreach ($broken as $process) {
                foreach (preg_split('/\R/', trim($process->getOutput()."\n".$process->getErrorOutput())) as $line) {
                    if (trim($line) !== '') {
                        $this->line('    <fg=yellow>'.$line.'</>');
                    }
                }

                $this->newLine();
            }

            $this->line('  Send that to whoever maintains this and it will be obvious.');

            return self::FAILURE;
        }

        $won = count(array_filter($endings, fn (?int $code) => $code === self::SOLD));

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

        $expected = $stock - ($allowed * $want);

        if ($won === $allowed && $remaining === $expected && $cached === $remaining) {
            $this->components->info('The lock holds. Two tills cannot oversell the same item on this database.');

            return self::SUCCESS;
        }

        // Which way it went wrong decides what to go and look at, so the two
        // are never reported as the same thing.
        if ($won > $allowed || $remaining < $expected) {
            $this->components->error('THE LOCK DID NOT HOLD. Do not go live on this database.');
            $this->line('  More was sold than there was to sell, which is what happens when');
            $this->line('  the row lock does nothing. The usual cause is MyISAM tables: no row');
            $this->line('  locks, no transactions, and MySQL does not complain — it ignores');
            $this->line('  both silently, and Laravel cannot tell.');
            $this->newLine();
            $this->line('  <fg=cyan>SELECT table_name, engine FROM information_schema.tables</>');
            $this->line('  <fg=cyan>WHERE table_schema = DATABASE();</>');
            $this->newLine();
            $this->line('  Anything that is not InnoDB needs converting:');
            $this->line('  <fg=cyan>ALTER TABLE stock_batches ENGINE=InnoDB;</>');

            return self::FAILURE;
        }

        if ($won < $allowed) {
            $this->components->warn('Nothing was oversold, but too few sales went through.');
            $this->line('  '.$won.' of a possible '.$allowed.' succeeded, and the stock is untouched.');
            $this->line('  The lock is not letting anything past rather than letting too much');
            $this->line('  past — a deadlock or a lock timeout, which is a stalled till rather');
            $this->line('  than a corrupted ledger. Safer than the other way round, but wrong.');
            $this->newLine();
            $this->line('  <fg=cyan>SHOW ENGINE INNODB STATUS;</> — look at LATEST DETECTED DEADLOCK.');

            return self::FAILURE;
        }

        $this->components->error('The stock cache disagrees with the batches.');
        $this->line('  The right number of sales went through, but products.quantity says');
        $this->line('  '.$cached.' where the batches say '.$remaining.'. Run <fg=cyan>php artisan stock:recheck</>.');

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
