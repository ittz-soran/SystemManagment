<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\Technician;
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
        ?Technician $technician = null,
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
    public function accept(Repair $repair, User $user, ?Technician $technician = null): Repair
    {
        $this->assertModifiable($repair);

        $repair->loadMissing('items');

        if ($repair->items->isEmpty()) {
            throw new RuntimeException(__('Decide what the job needs before the customer can accept it.'));
        }

        if ($repair->isAccepted()) {
            throw new RuntimeException(__('This job has already been accepted.'));
        }

        return DB::transaction(function () use ($repair, $technician) {
            $repair->status = Repair::STATUS_WORKING;
            $repair->accepted_at = now();
            $repair->accepted_total = $repair->total();

            if ($technician !== null) {
                $repair->technician_id = $technician->id;
            }

            $repair->save();

            return $repair->fresh('items', 'technician');
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
