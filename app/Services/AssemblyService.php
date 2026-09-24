<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Units;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking one thing apart, and putting several together — Soran, 2026-09-24.
 *
 * *"I purchased second hand ps5 slim digital have box and 2 controller -> I
 * purchased all at 750,000 -> today I want sale it but customer say need 1
 * controller!!"*, and *"this cases repeat daily in gaming pc build"*.
 *
 * ⚠️ **THE MONEY DOES NOT MOVE.** What comes out is worth exactly what went
 * in. A shop has neither earned nor lost anything by opening a box, so the
 * movements this writes net to zero in value and the profit report never sees
 * them. That single sentence is the whole design, and everything below is
 * there to keep it true.
 *
 * ⚠️ **Why not two stock adjustments.** An outgoing adjustment is a WRITE-OFF
 * and the profit report counts it as one — so a shop that took a 750,000
 * console apart would read a 750,000 loss that day, with a matching pile of
 * stock appearing from nowhere. The adjustment screen is for the shelf being
 * wrong; here the shelf was right.
 *
 * ⚠️ **One service, both directions, because they are the same arithmetic.**
 * Apart consumes one line and creates several; together consumes several and
 * creates one. Both consume at FIFO, both create at a cost that sums to what
 * was consumed, and the only real difference is which side the shopkeeper
 * types numbers into.
 */
class AssemblyService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
        private ProductCodeService $codes,
    ) {}

    /**
     * Take one thing to pieces.
     *
     * @param  array{product_id: int, quantity: int}  $whole  what goes in
     * @param  list<array{product_id?: int|null, name?: string|null, quantity: int, unit_cost: int, sale_price?: int|null}>  $pieces
     */
    public function takeApart(array $whole, array $pieces, User $user, ?Carbon $at = null, ?string $note = null): Assembly
    {
        return $this->build(Assembly::APART, [$whole], $pieces, $user, $at, $note);
    }

    /**
     * Put several things together.
     *
     * ⚠️ The result's cost is NOT typed. It is whatever FIFO charged for the
     * parts, which is the only figure that can be true — a shopkeeper who could
     * type it would be able to invent value out of nothing.
     *
     * @param  list<array{product_id: int, quantity: int}>  $pieces  what goes in
     * @param  array{product_id?: int|null, name?: string|null, quantity: int, sale_price?: int|null}  $whole
     */
    public function putTogether(array $pieces, array $whole, User $user, ?Carbon $at = null, ?string $note = null): Assembly
    {
        return $this->build(Assembly::TOGETHER, $pieces, [$whole], $user, $at, $note);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $results
     */
    private function build(
        string $direction,
        array $sources,
        array $results,
        User $user,
        ?Carbon $at,
        ?string $note,
    ): Assembly {
        $at ??= now();

        if (books_closed_on($at)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        $this->assertLinesAreSane($sources, $results);

        return DB::transaction(function () use ($direction, $sources, $results, $user, $at, $note) {
            $assembly = new Assembly([
                'direction' => $direction,
                'note' => $note,
                'assembled_at' => $at,
            ]);

            $assembly->document_no = $this->numbers->next(DocumentNumberService::PREFIX_ASSEMBLY);
            $assembly->user_id = $user->id;
            $assembly->total_cost = 0;
            $assembly->save();

            return $this->apply($assembly, $sources, $results, $user);
        });
    }

    /**
     * Put an assembly's figures onto the shelf.
     *
     * ⚠️ Shared by create and update so the two can never drift: whatever a
     * document does to the batches, it does here and nowhere else. Word for
     * word the arrangement `StockAdjustmentService` uses, for the same reason.
     *
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $results
     */
    private function apply(Assembly $assembly, array $sources, array $results, User $user): Assembly
    {
        $direction = $assembly->direction;
        $at = $assembly->assembled_at;

        // 1. What goes in comes off the shelf, at whatever it really cost.
        $consumed = 0;

        foreach ($sources as $index => $line) {
            $product = Product::whereKey($line['product_id'])->firstOrFail();

            if (! $product->tracksStock()) {
                throw new RuntimeException(__('A service holds no stock, so it cannot go into this.'));
            }

            $movements = $this->fifo->consume(
                product: $product,
                quantity: (int) $line['quantity'],
                referenceType: StockMovement::REF_ASSEMBLY,
                referenceId: $assembly->id,
                referenceItemId: null,
                occurredAt: $at,
                user: $user,
            );

            $cost = (int) $movements->sum(fn ($movement) => -$movement->quantity * $movement->unit_cost);
            $consumed += $cost;

            AssemblyItem::create([
                'assembly_id' => $assembly->id,
                'product_id' => $product->id,
                'role' => AssemblyItem::SOURCE,
                'quantity' => (int) $line['quantity'],

                /*
                 * ⚠️ What FIFO charged, per unit — and when a line spans
                 * two batches at different costs, that really is an
                 * average, so it can be a dinar out from the exact total.
                 * It is a figure to READ; the arithmetic below uses
                 * `$consumed`, which is the sum the movements actually
                 * wrote and is exact.
                 */
                'unit_cost' => intdiv($cost, max(1, (int) $line['quantity'])),
                'sequence' => $index + 1,
            ]);
        }

        // 2. What comes out goes on the shelf, at what the shop says each
        //    piece is worth — or, putting together, at the whole lot.
        $created = 0;

        foreach ($results as $index => $line) {
            $product = $this->resultProduct($line, $sources);

            $quantity = (int) $line['quantity'];

            /*
             * ⚠️ Putting together types no cost: the result is worth what
             * its parts cost, and a shopkeeper able to type it could invent
             * value out of nothing. Taking apart types every cost, and the
             * check below is what stops the same thing happening there.
             */
            if ($direction === Assembly::TOGETHER && $consumed % $quantity !== 0) {
                /*
                 * ⚠️ A batch carries ONE cost for all its units, so a total
                 * that does not divide evenly cannot be put on the shelf
                 * without losing the remainder — and losing it would break
                 * the balance check below on a document that is perfectly
                 * honest. Refused with the arithmetic in the message, since
                 * the way out is to build them one at a time.
                 */
                throw new RuntimeException(__('The parts come to :total, which does not divide evenly between :count. Build them one at a time, or change a quantity.', [
                    'total' => money($consumed, false),
                    'count' => number_format($quantity),
                ]));
            }

            $unitCost = $direction === Assembly::TOGETHER
                ? intdiv($consumed, $quantity)
                : (int) $line['unit_cost'];

            if ($unitCost < 0) {
                throw new RuntimeException(__('A cost cannot be negative.'));
            }

            $this->fifo->createBatch(
                product: $product,
                sourceType: StockBatch::SOURCE_ASSEMBLY,
                sourceId: $assembly->id,
                unitCost: $unitCost,
                quantity: $quantity,
                receivedAt: $at,
                sequence: $index + 1,
                user: $user,
            );

            $created += $unitCost * $quantity;

            AssemblyItem::create([
                'assembly_id' => $assembly->id,
                'product_id' => $product->id,
                'role' => AssemblyItem::RESULT,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'sequence' => $index + 1,
            ]);
        }

        /*
         * ⚠️ **THE CHECK THE WHOLE DOCUMENT EXISTS FOR.**
         *
         * Out to the last dinar. A penny of difference is a penny of profit
         * or loss invented by a shopkeeper typing numbers into a box, and
         * it would sit in the stock value forever with nothing to explain
         * it. Checked after the fact rather than before, so it is the
         * figures actually written that are compared — a check on the input
         * could still be defeated by a rounding done later.
         *
         * ⚠️ Putting together cannot fail this: its result's cost IS the
         * total, and a total that would not divide evenly between several
         * results is refused above rather than rounded away.
         */
        if ($created !== $consumed) {
            throw new RuntimeException(__('What comes out must be worth exactly what went in: :in in, :out out.', [
                'in' => money($consumed, false),
                'out' => money($created, false),
            ]));
        }

        $assembly->total_cost = $consumed;
        $assembly->save();

        return $assembly->refresh();

    }

    /**
     * Take an assembly's figures back off the shelf, exactly.
     *
     * The movements say which batches the units came from and how many, so
     * nothing is recomputed and nothing is guessed. ⚠️ Shared by delete and
     * update: an edit begins by undoing the original as completely as a delete
     * would, which is the only way the two can be trusted to agree.
     */
    private function unwind(Assembly $assembly): void
    {
        $movements = StockMovement::where('reference_type', StockMovement::REF_ASSEMBLY)
            ->where('reference_id', $assembly->id)
            ->lockForUpdate()
            ->get();

        $this->fifo->reverseMovements($movements);

        $assembly->items()->delete();
    }

    /**
     * Undo the whole thing.
     *
     * ⚠️ **What came out has to still be there.** Reversing puts the pieces
     * back into the batches they were made from and takes them off the shelf —
     * which cannot happen once somebody has sold one. The check is asked up
     * front so the screen can say why, and asked again inside the transaction
     * because the answer can change between a page loading and a button being
     * pressed.
     */
    public function delete(Assembly $assembly, User $user): void
    {
        $state = $assembly->canBeDeleted($user);

        if (! $state['allowed']) {
            throw new RuntimeException($state['reason']);
        }

        DB::transaction(function () use ($assembly, $user) {
            $state = $assembly->fresh()->canBeDeleted($user);

            if (! $state['allowed']) {
                throw new RuntimeException($state['reason']);
            }

            $this->unwind($assembly);

            $assembly->delete();
        });
    }

    /**
     * Do it again with different figures — Soran, 2026-09-24.
     *
     * Costs, pieces, quantities and the date. ⚠️ It is a full undo and redo,
     * not a patch: the shelf is put back exactly as it was and the new figures
     * are applied from scratch, so an edit can never leave half of the old
     * document behind. The document number and the row survive, so whatever
     * points at it still points at it.
     *
     * ⚠️ **Both dates are checked against the closed period** — the day it was
     * on and the day it is moving to. Editing a document out of closed books is
     * as much a change to them as editing one in.
     *
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $results
     */
    public function update(
        Assembly $assembly,
        array $sources,
        array $results,
        User $user,
        ?Carbon $at = null,
        ?string $note = null,
    ): Assembly {
        $at ??= $assembly->assembled_at;

        foreach ([$assembly->assembled_at, $at] as $date) {
            if (books_closed_on($date)) {
                throw new RuntimeException(__('Locked: this date is in a closed period.'));
            }
        }

        $state = $assembly->canBeDeleted($user, 'assemblies.create');

        if (! $state['allowed']) {
            throw new RuntimeException($state['reason']);
        }

        $this->assertLinesAreSane($sources, $results);

        return DB::transaction(function () use ($assembly, $sources, $results, $user, $at, $note) {
            $this->unwind($assembly);

            $assembly->forceFill([
                'assembled_at' => $at,
                'note' => $note,
                'total_cost' => 0,
            ])->save();

            return $this->apply($assembly->refresh(), $sources, $results, $user);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $results
     */
    private function assertLinesAreSane(array $sources, array $results): void
    {
        if ($sources === [] || $results === []) {
            throw new RuntimeException(__('Say what goes in and what comes out.'));
        }

        foreach ([...$sources, ...$results] as $line) {
            if ((int) ($line['quantity'] ?? 0) < 1) {
                throw new RuntimeException(__('Every line needs a quantity above zero.'));
            }
        }
    }

    /**
     * The product a result line lands in: one the shop already has, or a new
     * one made on the spot.
     *
     * ⚠️ Both, deliberately. A controller out of a PS5 box is a particular
     * second-hand thing and wants its own row; a stick of RAM out of a PC may
     * be something the shop already sells. The screen offers the list and a
     * box to type a new name in, and this decides which was meant.
     *
     * ⚠️ A piece of a second-hand thing is second-hand too. The kind is
     * inherited from what went in, so a used console does not produce brand-new
     * controllers on the shelf.
     *
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $sources
     */
    private function resultProduct(array $line, array $sources): Product
    {
        if (! empty($line['product_id'])) {
            return Product::whereKey($line['product_id'])->firstOrFail();
        }

        $name = trim((string) ($line['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException(__('Say what comes out, or choose a product.'));
        }

        $from = Product::whereKey($sources[0]['product_id'])->firstOrFail();
        $kind = $from->isUsed() ? Product::KIND_USED : Product::KIND_STOCK;

        $existing = Product::where('kind', $kind)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $codes = $this->codes->resolve([]);

        /*
         * ⚠️ Built and saved, not mass-assigned in one go — `quantity` is a
         * cache of the batches and belongs to the batch that is about to be
         * written, not to this. The same trap RepairService::buyPart noted.
         */
        $product = new Product([
            'name' => $name,
            'kind' => $kind,
            'sku' => $codes['sku'],
            'barcode' => $codes['barcode'],
            'category_id' => $from->category_id ?? Category::query()->orderBy('id')->value('id'),
            'unit' => Units::default(),
            'purchase_price' => (int) ($line['unit_cost'] ?? 0),
            'sale_price' => (int) ($line['sale_price'] ?? 0),
            'is_active' => true,
        ]);

        $product->quantity = 0;
        $product->save();

        return $product;
    }

    /**
     * Share a total out between lines, in the ratio of what they will sell for.
     *
     * ⚠️ **It returns a cost PER UNIT, not per line, and that is the whole
     * difficulty.** A line of two controllers carries one cost for both, so a
     * line total of 150,001 simply cannot be expressed — and a first version
     * that shared out line totals and divided afterwards left a dinar stranded,
     * handing the shopkeeper a form that its own balance check would refuse.
     *
     * So the remainder is walked back out in whole units, over every line,
     * smallest quantity first. ⚠️ The ORDER is only a tie-breaker about where a
     * few dinars sit — the walk visits every line either way, so it lands
     * whenever any arrangement could. Smallest first because a line of one
     * absorbs the whole remainder in a single step, which keeps the fewest
     * lines away from the ratio they were given.
     *
     * With a single piece anywhere in the document — and there almost always is
     * one, the console, the case — it lands exactly.
     *
     * ⚠️ When it cannot land exactly (every line has a quantity above one, and
     * the total does not divide), what is left is simply not shared. The screen
     * still shows it as outstanding and the shopkeeper places it. **A button
     * that silently loses a dinar would be worse than one that stops short**,
     * because the loss would be in the stock value forever with nothing to
     * explain it.
     *
     * @param  Collection<int, array{value: int, quantity: int}>  $lines
     * @return Collection<int, int> line key => cost per unit
     */
    public function shareOut(Collection $lines, int $total): Collection
    {
        if ($lines->isEmpty()) {
            return collect();
        }

        $weights = $lines->map(fn (array $line) => max(0, (int) $line['value']) * max(1, (int) $line['quantity']));
        $worth = $weights->sum();

        // Nothing to go on: weight every unit the same, which is the fairest
        // thing that can be said about pieces nobody has priced.
        if ($worth <= 0) {
            $weights = $lines->map(fn (array $line) => max(1, (int) $line['quantity']));
            $worth = $weights->sum();
        }

        $costs = $lines->map(function (array $line, $key) use ($weights, $worth, $total) {
            $quantity = max(1, (int) $line['quantity']);

            return intdiv(intdiv($weights[$key] * $total, $worth), $quantity);
        });

        $left = $total - $lines->sum(fn (array $line, $key) => $costs[$key] * max(1, (int) $line['quantity']));

        /*
         * The remainder, in whole units. Smallest quantity first, because a
         * line of one can take any amount and a line of five only multiples of
         * five — so spending it on the fussy lines first would strand it.
         */
        foreach ($lines->keys()->sortBy(fn ($key) => max(1, (int) $lines[$key]['quantity']))->all() as $key) {
            if ($left <= 0) {
                break;
            }

            $quantity = max(1, (int) $lines[$key]['quantity']);
            $each = intdiv($left, $quantity);

            if ($each > 0) {
                $costs[$key] += $each;
                $left -= $each * $quantity;
            }
        }

        return $costs;
    }
}
