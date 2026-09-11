<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
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
 *
 * **What running it for the first time taught, 2026-09-11.**
 *
 * Two things it checks now that it did not before. Overselling is the loudest
 * failure but not the only one: two sales sharing an invoice number, or a
 * customer balance that lost an update, are just as broken and entirely silent.
 * Both are checked from data the run already produces.
 *
 * And one thing worth knowing before reading a green result. `document_no` is
 * taken *inside* the sale's transaction, with `lockForUpdate()` on the counter
 * row (DocumentNumberService), so that row is held until the sale commits —
 * which means **two tills serialise from their very first statement**. Sales
 * cannot deadlock against each other at all, and the product-order sort in
 * SaleService is defence in depth rather than the thing standing between this
 * shop and a cycle. That was not a guess: the multi-item race was run again
 * with the sort commented out and passed identically. So a green result here is
 * evidence about overselling, numbering and balances; it is not evidence that
 * the sort works, because nothing reachable through a sale can test it.
 *
 * The practical consequence is throughput, not correctness: the shop makes one
 * sale at a time, shop-wide. At a few tens of milliseconds each that is hundreds
 * a minute, which is not a limit a counter will ever meet.
 */
class ProveLocking extends Command
{
    protected $signature = 'stock:prove-locking
                            {--database= : An EMPTY database to use. Never the shop\'s own.}
                            {--racers=2 : How many tills to race}
                            {--stock=5 : Units put on the shelf}
                            {--want=4 : Units each till tries to take}
                            {--products=1 : How many different items each sale touches}
                            {--child : Internal. One racer, started by the run above.}
                            {--ids= : Internal. The products to sell, comma separated.}
                            {--reverse : Internal. Sell them in the opposite order.}
                            {--at= : Internal. The instant to strike, as a unix timestamp.}';

    protected $description = 'Prove that two tills cannot oversell the same item on this machine\'s database';

    public function handle(): int
    {
        return $this->option('child') ? $this->race() : $this->prove();
    }

    // ---- The racer ------------------------------------------------------

    /** Sold on loan, so the racers are refused by stock or by nothing at all. */
    private const CUSTOMER = 'Contested customer';

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
            $ids = array_map('intval', explode(',', (string) $this->option('ids')));

            // Half the tills ring the same basket up backwards. That is the
            // deadlock Section 5 warns about: two sales holding one another's
            // first item and each waiting for the other's second. The service
            // is supposed to sort the lines by product before it locks
            // anything, and this is the only way to find out whether it does.
            if ($this->option('reverse')) {
                $ids = array_reverse($ids);
            }

            app(SaleService::class)->create(
                // The named test customer, not `first()`. The first customer in
                // a seeded shop is the Cash Customer, who must pay in full — so
                // every racer was refused by the business rules before it ever
                // reached a lock, and this tool has never once measured the
                // thing it exists to measure.
                customer: Customer::where('name', self::CUSTOMER)->firstOrFail(),
                lines: array_map(fn (int $id) => [
                    'product_id' => $id,
                    'quantity' => (int) $this->option('want'),
                    'unit_price' => 10_000,
                ], $ids),
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

        $howMany = max(1, (int) $this->option('products'));

        $this->components->info($howMany === 1
            ? "Racing {$racers} tills for {$stock} units, each wanting {$want}."
            : "Racing {$racers} tills over {$howMany} items — half of them ringing the basket up backwards.");

        $products = $this->shelf($stock, $howMany);
        $ids = $products->pluck('id')->implode(',');

        // Far enough ahead that every racer has booted and is waiting on it.
        $at = microtime(true) + 3.0;
        $php = (new PhpExecutableFinder)->find() ?: 'php';

        $processes = [];

        foreach (range(1, $racers) as $i) {
            $arguments = [
                $php, 'artisan', 'stock:prove-locking',
                '--child',
                '--database='.$database,
                '--ids='.$ids,
                '--want='.$want,
                '--at='.$at,
            ];

            // Every other till takes the same items in the opposite order.
            if ($i % 2 === 0) {
                $arguments[] = '--reverse';
            }

            $processes[] = tap(new Process($arguments, base_path(), null, null, 60))->start();
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

        return $this->verdict($products, $won, $stock, $want, $racers);
    }

    /**
     * The contested items, each with its own batch and a known number of units.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function shelf(int $stock, int $howMany)
    {
        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $category = Category::firstOrCreate(['name' => 'Test']);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $supplier = Supplier::create(['name' => 'Test supplier']);

        $products = collect(range(1, $howMany))->map(fn (int $n) => Product::create([
            'name' => 'Contested item '.$n,
            'sku' => 'LOCK-'.$n,
            'category_id' => $category->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 10_000,
            'quantity' => 0,
        ]));

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: $products->map(fn (Product $p) => [
                'product_id' => $p->id, 'quantity' => $stock, 'unit_price' => 1_000,
            ])->all(),
            user: $admin,
            purchaseDate: now(),
        );

        Customer::firstOrCreate(['name' => self::CUSTOMER]);

        return $products;
    }

    private function verdict($products, int $won, int $stock, int $want, int $racers): int
    {
        DB::purge();
        $this->connect();

        $ids = $products->pluck('id');
        $howMany = $ids->count();

        $remaining = (int) StockBatch::whereIn('product_id', $ids)->sum('quantity_remaining');
        $cached = (int) Product::whereIn('id', $ids)->sum('quantity');

        // How many could honestly have won. The shelf is one limit and the
        // number of tills is the other — eight tills cannot make ten sales, and
        // a tool that expects them to reports a healthy lock as a deadlock.
        $allowed = min($racers, intdiv($stock, $want));

        // Section 5 names three things that need locking, not one. Overselling
        // is the loudest, but a shop is just as broken by two invoices sharing a
        // number, or by a balance that lost an update — and both of those are
        // silent. The data to check them is already here.
        $sales = Sale::where('customer_id', Customer::where('name', self::CUSTOMER)->value('id'))->get();
        $numbered = $sales->pluck('document_no')->unique()->count();
        $billed = (int) $sales->sum('total_amount');
        $owed = (int) Customer::where('name', self::CUSTOMER)->value('balance');

        $this->newLine();
        $this->table(['What', 'Result', 'Should be'], [
            ['Sales that went through', $won, $allowed],
            ['Units left in the batches', $remaining, $howMany * ($stock - ($allowed * $want))],
            ['products.quantity cache', $cached, $remaining],
            ['Invoice numbers, all different', $numbered, $sales->count()],
            ['What the customer owes', number_format($owed), number_format($billed)],
        ]);

        $expected = $howMany * ($stock - ($allowed * $want));

        // Reported before the stock verdict, because a shop that oversells knows
        // within the hour and one that double-numbers an invoice finds out from
        // its accountant in March.
        if ($numbered !== $sales->count()) {
            $this->components->error('TWO SALES SHARE AN INVOICE NUMBER.');
            $this->line('  '.$sales->count().' sales went through carrying only '.$numbered.' distinct numbers.');
            $this->line('  The document_counters row is not being locked (Section 7b), so two');
            $this->line('  tills read the same next number and both took it.');

            return self::FAILURE;
        }

        if ($owed !== $billed) {
            $this->components->error('THE CUSTOMER BALANCE LOST AN UPDATE.');
            $this->line('  The sales add up to '.number_format($billed).' but the balance says '.number_format($owed).'.');
            $this->line('  Two tills read the same balance and each wrote its own total over');
            $this->line('  the other. Look at LedgerService and the lock on the account row.');

            return self::FAILURE;
        }

        if ($won === $allowed && $remaining === $expected && $cached === $remaining) {
            $this->components->info('The locks hold: no overselling, no shared invoice number, no lost balance.');

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
