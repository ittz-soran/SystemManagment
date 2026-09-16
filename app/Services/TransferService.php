<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockRoom;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrying goods from one room to another — Soran, 2026-09-15.
 *
 * ⚠️ **A transfer SPLITS a layer, it does not move one.** This is the whole
 * design and everything else follows from it.
 *
 * The obvious build is `UPDATE stock_batches SET room_id = ?`. It is wrong, and
 * wrong in a way that would not show up for months: a batch is one layer of
 * stock at one cost, and a partial transfer — twelve out of a batch of twenty —
 * has no single room to be in. Moving the row would carry all twenty; moving
 * none of it would lose the twelve.
 *
 * So the units come OUT of the source layer and a NEW layer is made in the
 * destination, carrying the same `unit_cost`, the same `received_at` and the
 * same `sequence`. Three things fall out of that, all of them wanted:
 *
 *   The cost travels with the goods. Nothing is re-valued by being carried
 *   across a yard, so a sale from the back room later reports the cost the shop
 *   actually paid.
 *
 *   FIFO in the far room still reflects the order the shop bought things,
 *   because `received_at` is the purchase's, not today's. Carrying old stock to
 *   the back and new stock to the front cannot reorder the books.
 *
 *   The pair of movements sums to zero, so `SUM(quantity)` per product still
 *   equals current stock and every integrity check stays true.
 */
class TransferService
{
    public function __construct(
        private readonly FifoService $fifo,
        private readonly DocumentNumberService $numbers,
        private readonly ActivityLogger $log,
    ) {}

    /**
     * Move goods between two rooms.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     */
    public function create(
        StockRoom $from,
        StockRoom $to,
        array $lines,
        Carbon $transferredAt,
        ?string $note,
        User $user,
    ): StockTransfer {
        if ($from->id === $to->id) {
            throw new RuntimeException(__('Choose two different rooms.'));
        }

        if (! $to->is_active) {
            throw new RuntimeException(__('That room is closed, so nothing can be moved into it.'));
        }

        $lines = $this->tidy($lines);

        if ($lines === []) {
            throw new RuntimeException(__('Add at least one product.'));
        }

        return DB::transaction(function () use ($from, $to, $lines, $transferredAt, $note, $user) {
            /*
             * ⚠️ Every product claimed up front, in one order, before anything
             * is written — the same discipline as SaleService, and for the same
             * measured reason. A transfer takes batch rows in one room and
             * makes rows in another; a sale meeting it on the same product is
             * exactly the cycle FifoService::claim() exists to prevent.
             */
            $this->fifo->claim(array_column($lines, 'product_id'));

            $transfer = StockTransfer::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_TRANSFER),
                'from_room_id' => $from->id,
                'to_room_id' => $to->id,
                'transferred_at' => $transferredAt,
                'note' => $note,
                'user_id' => $user->id,
            ]);

            foreach ($lines as $sequence => $line) {
                $product = Product::whereKey($line['product_id'])->firstOrFail();

                /*
                 * ⚠️ A service has no stock to carry. It is not an error worth
                 * a message — it is a thing that cannot be on this document at
                 * all, and the screen does not offer it.
                 */
                if (! $product->tracksStock()) {
                    throw new RuntimeException(__(':product is a service and holds no stock.', [
                        'product' => $product->name,
                    ]));
                }

                $item = StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'sequence' => $sequence,
                ]);

                $this->carry($transfer, $item, $product, $from, $to, $transferredAt, $user);
            }

            $this->log->log(
                action: 'create',
                module: 'stock_transfers',
                recordId: $transfer->id,
                description: __('Moved stock from :from to :to (:document)', [
                    'from' => $from->name,
                    'to' => $to->name,
                    'document' => $transfer->document_no,
                ]),
                user: $user,
            );

            return $transfer->fresh(['items', 'fromRoom', 'toRoom']);
        });
    }

    /**
     * Put a transfer back exactly as it was.
     *
     * ⚠️ Refused once any of the carried units have been sold from the far
     * room. The layers this created are real stock the moment they exist, and
     * undoing a document whose goods are gone would either take a batch
     * negative or quietly lose the sale's cost — see reverseMovements(), which
     * makes the same refusal for the same reason.
     */
    public function delete(StockTransfer $transfer, User $user): void
    {
        DB::transaction(function () use ($transfer, $user) {
            $movements = StockMovement::where('reference_type', StockMovement::REF_TRANSFER)
                ->where('reference_id', $transfer->id)
                ->get();

            $this->fifo->claim($movements->pluck('product_id'));

            // The destination layers this document created. They are only
            // removable while nothing has drawn from them.
            $created = StockBatch::where('source_type', StockBatch::SOURCE_TRANSFER)
                ->where('source_id', $transfer->id)
                ->lockForUpdate()
                ->get();

            foreach ($created as $batch) {
                if ($batch->quantity_remaining !== $batch->quantity_in) {
                    throw new RuntimeException(trans_choice(
                        '{1}Cannot undo this: :count unit that was moved has since been sold or written off.'
                        .'|[2,*]Cannot undo this: :count units that were moved have since been sold or written off.',
                        $batch->quantity_in - $batch->quantity_remaining,
                        ['count' => $batch->quantity_in - $batch->quantity_remaining],
                    ));
                }
            }

            // Give the units back to the layers they were split from, then
            // remove the layers this document made and the movements with them.
            foreach ($movements->sortByDesc('id') as $movement) {
                if ($movement->quantity < 0) {
                    $source = StockBatch::whereKey($movement->stock_batch_id)->lockForUpdate()->firstOrFail();

                    $source->forceFill([
                        'quantity_remaining' => $source->quantity_remaining - $movement->quantity,
                    ])->save();
                }

                $movement->delete();
            }

            $created->each->delete();

            foreach ($movements->pluck('product_id')->unique() as $productId) {
                $this->fifo->syncProductQuantity(Product::findOrFail($productId));
            }

            $this->log->log(
                action: 'delete',
                module: 'stock_transfers',
                recordId: $transfer->id,
                description: __('Undid stock move :document', ['document' => $transfer->document_no]),
                user: $user,
            );

            $transfer->delete();
        });
    }

    /**
     * Take one product's units out of one room and make them a layer in another.
     *
     * Oldest first, exactly as a sale would: the goods that have been in the
     * shop longest are the ones that go to the back room, which is both what a
     * shopkeeper does and what keeps the two rooms' FIFO consistent with each
     * other.
     */
    private function carry(
        StockTransfer $transfer,
        StockTransferItem $item,
        Product $product,
        StockRoom $from,
        StockRoom $to,
        Carbon $transferredAt,
        User $user,
    ): void {
        $batches = StockBatch::where('product_id', $product->id)
            ->inRoom($from->id)
            ->withStock()
            ->fifoOrder()
            ->lockForUpdate()
            ->get();

        $available = (int) $batches->sum('quantity_remaining');

        if ($available < $item->quantity) {
            throw new InsufficientStockException(
                $available,
                $item->quantity,
                __('Not enough :product in :room: :count available.', [
                    'product' => $product->name,
                    'room' => $from->name,
                    'count' => number_format($available),
                ]),
            );
        }

        $remaining = $item->quantity;
        $sequence = 1;

        foreach ($batches as $batch) {
            if ($remaining === 0) {
                break;
            }

            $take = min($batch->quantity_remaining, $remaining);

            // Out of the source layer.
            $batch->forceFill([
                'quantity_remaining' => $batch->quantity_remaining - $take,
            ])->save();

            StockMovement::create([
                'product_id' => $product->id,
                'stock_batch_id' => $batch->id,
                'reference_type' => StockMovement::REF_TRANSFER,
                'reference_id' => $transfer->id,
                'reference_item_id' => $item->id,
                'quantity' => -$take,
                'unit_cost' => $batch->unit_cost,
                'occurred_at' => $transferredAt,
                'sequence' => $sequence++,
                'user_id' => $user->id,
            ]);

            /*
             * ⚠️ And into a NEW layer that keeps the source's cost and date.
             *
             * `received_at` and `sequence` are the SOURCE's, not today's. FIFO
             * in the destination room then still reflects the order the shop
             * bought things — otherwise carrying old stock to the back room
             * would make it the newest thing there, and the next sale from that
             * room would draw the wrong cost.
             */
            $carried = StockBatch::create([
                'product_id' => $product->id,
                'room_id' => $to->id,
                'source_type' => StockBatch::SOURCE_TRANSFER,
                'source_id' => $transfer->id,
                'purchase_item_id' => null,
                'parent_batch_id' => $batch->id,
                'unit_cost' => $batch->unit_cost,
                'quantity_in' => $take,
                'quantity_remaining' => $take,
                'received_at' => $batch->received_at,
                'sequence' => $batch->sequence,
            ]);

            StockMovement::create([
                'product_id' => $product->id,
                'stock_batch_id' => $carried->id,
                'reference_type' => StockMovement::REF_TRANSFER,
                'reference_id' => $transfer->id,
                'reference_item_id' => $item->id,
                'quantity' => $take,
                'unit_cost' => $batch->unit_cost,
                'occurred_at' => $transferredAt,
                'sequence' => $sequence++,
                'user_id' => $user->id,
            ]);

            $remaining -= $take;
        }

        // ⚠️ The shop owns exactly as much as it did a moment ago, so this
        // cannot change the number — it is called because the cache is written
        // inside the same transaction as every movement, and a transfer that
        // skipped it would be the one path that did not.
        $this->fifo->syncProductQuantity($product);
    }

    /**
     * One line per product, positive quantities only.
     *
     * The same product twice on one transfer is not an error worth refusing —
     * it is somebody typing a crate, then remembering another crate. Added
     * together it says what they meant.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     * @return array<int, array{product_id: int, quantity: int}>
     */
    private function tidy(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($productId === 0 || $quantity <= 0) {
                continue;
            }

            $merged[$productId] = ($merged[$productId] ?? 0) + $quantity;
        }

        return array_values(array_map(
            fn (int $productId, int $quantity) => ['product_id' => $productId, 'quantity' => $quantity],
            array_keys($merged),
            $merged,
        ));
    }
}
