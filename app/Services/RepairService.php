<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\RepairApproval;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The workshop book — Soran, 2026-09-20.
 *
 * ⚠️ **NOTHING HERE MOVES STOCK OR MONEY.** A job holds the parts it needs as
 * lines and leaves them on the shelf. `collect()` makes an ordinary `Sale` out
 * of those lines, and that sale is what consumes FIFO, charges the cost, posts
 * to the ledger and takes the payment — through machinery that already exists
 * and is already tested.
 *
 * Fitting a part into a phone and billing for it separately would need a second
 * path through FIFO, and Section 5 is the part of this system least able to
 * afford one: it is where the worst bugs here have come from.
 */
class RepairService
{
    public function __construct(private DocumentNumberService $numbers) {}

    /** @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $lines */
    public function create(
        Customer $customer,
        string $device,
        string $fault,
        User $user,
        ?Carbon $receivedAt = null,
        ?string $identifier = null,
        ?string $conditionNote = null,
        ?Carbon $promisedFor = null,
        ?int $estimate = null,
        ?string $note = null,
        array $lines = [],
        ?User $technician = null,
    ): Repair {
        return DB::transaction(function () use (
            $customer, $device, $fault, $user, $receivedAt, $identifier,
            $conditionNote, $promisedFor, $estimate, $note, $lines, $technician
        ) {
            $repair = new Repair([
                'customer_id' => $customer->id,
                'device' => trim($device),
                'identifier' => $identifier === null ? null : trim($identifier),
                'fault' => trim($fault),
                'condition_note' => $conditionNote === null ? null : trim($conditionNote),
                'received_at' => $receivedAt ?? now(),
                'promised_for' => $promisedFor,
                'estimate' => $estimate,
                'status' => Repair::STATUS_RECEIVED,
                'note' => $note,
                'technician_id' => $technician?->id,
            ]);

            $repair->document_no = $this->numbers->next(DocumentNumberService::PREFIX_REPAIR);
            $repair->user_id = $user->id;
            $repair->save();

            $this->setLines($repair, $lines);

            return $repair->fresh('items');
        });
    }

    /**
     * Replace the job's lines with these.
     *
     * Replaced rather than merged because that is what the screen sends: the
     * whole list as it now stands. Nothing has moved, so nothing has to be
     * unwound — which is the quiet benefit of leaving the stock alone until
     * collection.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $lines
     */
    public function setLines(Repair $repair, array $lines): Repair
    {
        $this->assertModifiable($repair);

        return DB::transaction(function () use ($repair, $lines) {
            $repair->items()->delete();

            foreach ($lines as $line) {
                if ((int) ($line['quantity'] ?? 0) < 1) {
                    continue;
                }

                /*
                 * ⚠️ The warranty is COPIED from the product, not read through
                 * a relation. A screen carries 5 days and a battery 30 — and
                 * what was promised on a ticket must not change because
                 * somebody edited the product next month. Same reasoning as
                 * the price beside it.
                 */
                $product = Product::find((int) $line['product_id']);

                $repair->items()->create([
                    'product_id' => (int) $line['product_id'],
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => (int) ($line['unit_price'] ?? 0),
                    'warranty_days' => array_key_exists('warranty_days', $line)
                        ? $line['warranty_days']
                        : $product?->warranty_days,
                ]);
            }

            return $repair->fresh('items');
        });
    }

    /**
     * The customer says yes, and the ticket becomes real.
     *
     * ⚠️ **This is the event the printed ticket comes from** — Soran:
     * *"after customer accept about parts and cost of repairing → system save
     * job as on Working and print an Ticket"*. What is frozen here is what the
     * customer walks out holding: the total they agreed to, and the warranty
     * offered on each line.
     *
     * The job's live total may move afterwards, and that is allowed — prices do
     * change mid-repair. What may not happen is the agreed figure being
     * quietly overwritten, because the paper in their hand still says it.
     */
    public function accept(
        Repair $repair,
        User $user,
        ?User $technician = null,
        string $channel = RepairApproval::CHANNEL_COUNTER,
        ?string $note = null,
    ): Repair {
        $this->assertModifiable($repair);

        $repair->loadMissing('items');

        if ($repair->items->isEmpty()) {
            throw new RuntimeException(__('Decide what the job needs before the customer can accept it.'));
        }

        /*
         * ⚠️ Agreeing AGAIN is the normal case, not an error.
         *
         * Soran's PS4 was agreed at 8,000 across the counter and again at
         * 43,000 on the telephone when the drive turned out to be failing. What
         * is refused is a pointless second yes to the same figure.
         */
        if (! $repair->needsApproval()) {
            throw new RuntimeException(__('Nothing has changed since the customer last agreed.'));
        }

        if (! in_array($channel, RepairApproval::CHANNELS, true)) {
            throw new RuntimeException(__('Say how the customer was told.'));
        }

        return DB::transaction(function () use ($repair, $technician, $channel, $note, $user) {
            /*
             * ⚠️ Built and saved once, not created then patched. `user_id` is
             * not fillable — who recorded the agreement is the system's to
             * know, never a form's — so `create()` inserts without it and the
             * NOT NULL constraint refuses the row. The fourth thing today that
             * mass assignment dropped without a word.
             */
            $approval = new RepairApproval([
                'total' => $repair->total(),
                'channel' => $channel,
                'note' => $note,
                'approved_at' => now(),
            ]);

            $approval->user_id = $user->id;

            $repair->approvals()->save($approval);

            $repair->status = Repair::STATUS_WORKING;
            $repair->accepted_at ??= now();
            $repair->accepted_total = $repair->total();

            if ($technician !== null) {
                $repair->technician_id = $technician->id;
            }

            $repair->save();

            // Reloaded rather than left as it was: `needsApproval()` above
            // loaded this relation while it was still empty.
            $repair->load('approvals', 'items', 'technician');

            return $repair;
        });
    }

    /** Move the job along the bench. */
    public function setStatus(Repair $repair, string $status, User $user): Repair
    {
        if (! in_array($status, Repair::STATUSES, true)) {
            throw new RuntimeException(__('That is not a status a repair can be in.'));
        }

        /*
         * ⚠️ Collection is not a status change, it is a sale.
         *
         * Letting it through here would mark a job collected with no invoice,
         * no stock movement and no money — the one state this module must not
         * be able to reach.
         */
        if ($status === Repair::STATUS_COLLECTED) {
            throw new RuntimeException(__('A repair is collected by taking payment for it, not by changing its status.'));
        }

        /*
         * ⚠️ And `working` is reached by the customer accepting, not by a flip.
         *
         * Going straight there would leave a job being worked on with no agreed
         * total and no ticket — so nothing to hold the shop to, and nothing for
         * the customer to bring back.
         */
        if ($status === Repair::STATUS_WORKING && ! $repair->isAccepted()) {
            throw new RuntimeException(__('The customer has to accept the parts and the price first.'));
        }

        $this->assertModifiable($repair);

        $repair->update(['status' => $status]);

        return $repair->fresh();
    }

    /**
     * The customer collects: the job becomes a sale.
     *
     * ⚠️ This is the only place a repair touches stock or money, and it does so
     * by asking `SaleService` rather than by doing it. Every consequence the
     * shop cares about — FIFO cost, the ledger, the invoice, the P&L, being
     * able to take it back — is that sale's, and is already right.
     */
    public function collect(
        Repair $repair,
        User $user,
        int $amountPaid = 0,
        string $paymentMethod = 'cash',
        ?Carbon $collectedAt = null,
    ): Sale {
        $repair->loadMissing('items', 'customer');

        if ($repair->status === Repair::STATUS_COLLECTED) {
            throw new RuntimeException(__('This repair has already been collected.'));
        }

        if ($repair->status === Repair::STATUS_RETURNED) {
            throw new RuntimeException(__('This repair was handed back unrepaired. Reopen it first.'));
        }

        if ($repair->items->isEmpty()) {
            throw new RuntimeException(__('Add what the customer is paying for — the parts, the labour, or both.'));
        }

        /*
         * ⚠️ NOBODY IS CHARGED FOR WORK THEY DID NOT AGREE TO.
         *
         * Either the job was never agreed at all, or it has changed since — the
         * failing drive found mid-repair. Soran's own practice is to telephone
         * before touching it; this is that practice made into a rule, and the
         * message says what to do rather than only refusing.
         */
        $repair->load('approvals');

        if ($repair->needsApproval()) {
            throw new RuntimeException($repair->lastApproval() === null
                ? __('The customer has not agreed to this job yet.')
                : __('The job has changed since the customer agreed to :amount. Call them, then record that they accepted.', [
                    'amount' => money($repair->lastApproval()->total),
                ]));
        }

        return DB::transaction(function () use ($repair, $user, $amountPaid, $paymentMethod, $collectedAt) {
            $sale = app(SaleService::class)->create(
                customer: $repair->customer,
                lines: $repair->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ])->all(),
                user: $user,
                saleDate: $collectedAt ?? now(),
                amountPaid: $amountPaid,
                paymentMethod: $paymentMethod,
            );

            /*
             * ⚠️ Assigned, not mass-assigned. `sale_id` is deliberately absent
             * from the model's fillable list — it is the system's link to the
             * invoice, never something a form may set — and `update()` drops
             * silently what it is not allowed to write. The job came out
             * `collected` with no sale against it, which is the one state this
             * module must not be able to reach.
             */
            $repair->status = Repair::STATUS_COLLECTED;
            $repair->sale_id = $sale->id;
            $repair->save();

            return $sale;
        });
    }

    /**
     * Handed back unmended, which is an outcome rather than a mistake.
     *
     * No sale, because nothing was sold. The parts stay listed so the shop can
     * see what it had set aside, and they were never off the shelf anyway.
     */
    public function handBack(Repair $repair, User $user, ?string $why = null): Repair
    {
        $this->assertModifiable($repair);

        $repair->update([
            'status' => Repair::STATUS_RETURNED,
            'note' => $why ?: $repair->note,
        ]);

        return $repair->fresh();
    }

    /**
     * What this job costs the shop, in total and line by line — Soran, 2026-09-22.
     *
     * *"see both sale price and cost of same batch by permission"*. Not the
     * product's list cost: the cost of the **batches FIFO actually takes**,
     * which for three screens bought at 20,000 and a fourth at 24,000 is a
     * different number depending on which one goes out.
     *
     * ⚠️ **Two sources, and which one is right depends on whether the job has
     * been collected.**
     *
     * Collected, it is read from the `stock_movements` the sale wrote — the
     * same rows Profit & Loss adds up, so a job's profit and the shop's profit
     * cannot disagree. Not collected, nothing has moved yet, so it is a
     * forecast off the queue as it stands today: honest, and labelled as a
     * forecast rather than passed off as fact.
     *
     * Returns raw figures. ⚠️ Masking belongs to `cost_seen()` where they are
     * displayed, and every figure derived from one — the profit beside it —
     * must be derived from the MASKED number, or the real cost is a single
     * subtraction away from a marked-up one.
     *
     * @return array{cost: int, real: bool, short: int, refunded: int, lines: array<int, int>}
     */
    public function costOf(Repair $repair): array
    {
        $repair->loadMissing('items.product');

        return $repair->sale_id === null
            ? $this->forecastCost($repair)
            : $this->settledCost($repair);
    }

    /**
     * What the job actually cost, off the sale that collected it.
     *
     * ⚠️ Lines are matched by POSITION, not by product. `collect()` hands
     * `SaleService` the repair's lines in order and it numbers them `sequence`
     * 1, 2, 3 — so position is exact, where matching on product would have to
     * guess which of two lines for the same screen took the older batch.
     *
     * @return array{cost: int, real: bool, short: int, refunded: int, lines: array<int, int>}
     */
    private function settledCost(Repair $repair): array
    {
        $bySaleItem = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $repair->sale_id)
            ->groupBy('reference_item_id')
            ->selectRaw('reference_item_id, SUM(-'.StockMovement::VALUE.') as cost')
            ->pluck('cost', 'reference_item_id');

        $saleItemBySequence = SaleItem::where('sale_id', $repair->sale_id)
            ->pluck('id', 'sequence');

        /*
         * ⚠️ WHAT CAME BACK COMES OFF, OR THE JOB CLAIMS A PROFIT THE SHOP
         * NEVER MADE.
         *
         * A customer who brings the television back and takes his money is an
         * ordinary afternoon, and the board goes onto the shelf with its cost
         * reversed. Reading only the sale's movements would leave the job
         * saying "charged 70,000, cost 30,000, made 40,000" about a board the
         * shop is holding and money it has handed over. The per-person report
         * already nets refunds off; a job screen that did not would put two
         * different answers about one afternoon on two screens of one system.
         *
         * `quantity_returned` on the sale item is what ties the money back to
         * the line, and the return's own movements carry the cost back.
         */
        $returns = SaleReturn::where('sale_id', $repair->sale_id)->pluck('id');

        $costBack = $returns->isEmpty() ? collect() : StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
            ->whereIn('reference_id', $returns)
            ->groupBy('reference_item_id')
            ->selectRaw('reference_item_id, SUM('.StockMovement::VALUE.') as cost')
            ->pluck('cost', 'reference_item_id');

        /*
         * The return's items point at the sale item they undo, so the cost that
         * came back is put against the same line the cost went out on.
         */
        $backBySaleItem = [];

        if ($returns->isNotEmpty()) {
            foreach (SaleReturnItem::whereIn('sale_return_id', $returns)->get() as $returnItem) {
                $saleItemId = $returnItem->sale_item_id;
                $backBySaleItem[$saleItemId] = ($backBySaleItem[$saleItemId] ?? 0)
                    + (int) ($costBack[$returnItem->id] ?? 0);
            }
        }

        $refunded = (int) SaleReturn::where('sale_id', $repair->sale_id)->sum('total_amount');

        $lines = [];
        $total = 0;

        foreach ($repair->items->values() as $index => $item) {
            $saleItemId = $saleItemBySequence[$index + 1] ?? null;
            $cost = (int) ($bySaleItem[$saleItemId] ?? 0) - (int) ($backBySaleItem[$saleItemId] ?? 0);

            $lines[$item->id] = $cost;
            $total += $cost;
        }

        return ['cost' => $total, 'real' => true, 'short' => 0, 'refunded' => $refunded, 'lines' => $lines];
    }

    /**
     * What the job would cost if it were collected now.
     *
     * @return array{cost: int, real: bool, short: int, refunded: int, lines: array<int, int>}
     */
    private function forecastCost(Repair $repair): array
    {
        $lines = [];
        $total = 0;
        $short = 0;

        /*
         * ⚠️ Claimed across the whole job, not per line.
         *
         * Two lines for the same screen must not both be priced from the oldest
         * batch — FIFO would give the first one that batch and the second the
         * next. Without this the forecast for a job needing two of something is
         * quietly too low, and it is lowest exactly when the shop is about to
         * run out.
         */
        $taken = [];

        foreach ($repair->items as $item) {
            /*
             * A service has no stock and never will. Walking the batch queue
             * for one answers zero and then reports the whole quantity as
             * missing, so labour would show on the screen as a part on order.
             */
            if (! $item->product->tracksStock()) {
                $lines[$item->id] = 0;

                continue;
            }

            $wanted = (int) $item->quantity;
            $cost = 0;

            $batches = $item->product->stockBatches()->withStock()->fifoOrder()
                ->get(['id', 'quantity_remaining', 'unit_cost']);

            foreach ($batches as $batch) {
                if ($wanted < 1) {
                    break;
                }

                $left = (int) $batch->quantity_remaining - ($taken[$batch->id] ?? 0);

                if ($left < 1) {
                    continue;
                }

                $take = min($wanted, $left);
                $cost += $take * (int) $batch->unit_cost;
                $taken[$batch->id] = ($taken[$batch->id] ?? 0) + $take;
                $wanted -= $take;
            }

            /*
             * Not on the shelf yet — the part is on order, which is an ordinary
             * state for a job waiting on a screen. Valued at what the product
             * last cost, and counted, so the screen can say the figure is
             * incomplete rather than present a guess as a fact.
             */
            if ($wanted > 0) {
                $cost += $wanted * (int) $item->product->purchase_price;
                $short += $wanted;
            }

            $lines[$item->id] = $cost;
            $total += $cost;
        }

        return ['cost' => $total, 'real' => false, 'short' => $short, 'refunded' => 0, 'lines' => $lines];
    }

    /** ⚠️ A collected job owns a sale, and the two must not describe different work. */
    private function assertModifiable(Repair $repair): void
    {
        if (! $repair->canBeModified()) {
            throw new RuntimeException(__('This repair has been collected and paid for. Delete :invoice first if it is wrong.', [
                'invoice' => $repair->sale?->document_no ?? __('the sale'),
            ]));
        }
    }
}
