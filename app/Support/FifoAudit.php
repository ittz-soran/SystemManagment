<?php

namespace App\Support;

use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Did every sale really take the oldest layer? — Soran, 2026-09-25.
 *
 * ⚠️ **THIS EXISTS BECAUSE FIFO WAS ONCE SILENTLY WRONG AND EVERY SCREEN
 * AGREED WITH IT.** MySQL was rewriting `stock_batches.received_at` on every
 * update, so the column FIFO sorts by said "least recently touched" instead of
 * "oldest first" — and a sale that took the wrong layer is *self-consistent*
 * afterwards. The movement says what it cost, the report sums the movements,
 * the product page reads the same rows: every figure in the shop agrees, and
 * all of them are agreeing about the wrong batch.
 *
 * The column has been repaired and the column type changed so it cannot happen
 * again. What cannot be undone is the sales made while it was wrong. So this
 * replays the shop's whole history, movement by movement, and lists every
 * outbound line that took a layer while an older one still had stock.
 *
 * ⚠️ **It changes nothing, and that is deliberate.** Re-costing a past sale
 * would move profit between months that have been read, printed and perhaps
 * closed — history rewritten to make a report tidier. This shop's rule
 * everywhere else is that a correction is a new forward document, never an
 * edit to what happened. So the audit reports, and the shopkeeper decides.
 */
final class FifoAudit
{
    /**
     * The documents whose stock goes out through `FifoService::consume()`, and
     * which therefore must take the oldest layer.
     *
     * ⚠️ A purchase return is NOT one of them: it deducts from the batch that
     * purchase created, deliberately and by name — you are sending those
     * specific goods back to that supplier, not the oldest ones you happen to
     * hold. Auditing it against FIFO would report a finding on every single
     * one.
     */
    private const FIFO_OUT = [
        StockMovement::REF_SALE,
        StockMovement::REF_SWAP,
        StockMovement::REF_ADJUSTMENT,
        StockMovement::REF_ASSEMBLY,
    ];

    /**
     * Every outbound movement that took the wrong layer.
     *
     * @return Collection<int, object{
     *     movement_id: int, occurred_at: Carbon, reference_type: string, reference_id: int,
     *     product_id: int, product: string, units: int,
     *     took_batch: int, took_cost: int, took_received: Carbon,
     *     older_batch: int, older_cost: int, older_received: Carbon, older_left: int,
     *     difference: int}>
     */
    public function findings(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $findings = collect();

        /*
         * ⚠️ **Replayed per product AND per room.** The till sells one room, so
         * a layer sitting in the back is not a layer this sale skipped — it was
         * never a candidate. Auditing across rooms would report a finding on
         * every shop that keeps a second store.
         */
        foreach ($this->movementsByProductAndRoom() as $timeline) {
            $findings = $findings->merge($this->replay($timeline, $from, $to));
        }

        return $findings->sortByDesc(fn (object $row) => $row->occurred_at->getTimestamp())->values();
    }

    /** What the whole audit comes to, in one line. */
    public function summary(Collection $findings): array
    {
        return [
            'lines' => $findings->count(),
            'units' => (int) $findings->sum('units'),

            // Positive: the sale was charged MORE than the oldest layer cost,
            // so the shop's reported profit is lower than it really was.
            'difference' => (int) $findings->sum('difference'),
            'products' => $findings->pluck('product_id')->unique()->count(),
        ];
    }

    /**
     * Every movement in the shop, grouped into the timelines FIFO runs along.
     *
     * @return Collection<string, Collection<int, object>>
     */
    private function movementsByProductAndRoom(): Collection
    {
        return StockMovement::query()
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_movements.stock_batch_id')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->select([
                'stock_movements.id',
                'stock_movements.product_id',
                'stock_movements.stock_batch_id',
                'stock_movements.quantity',
                'stock_movements.unit_cost',
                'stock_movements.occurred_at',
                'stock_movements.sequence',
                'stock_movements.reference_type',
                'stock_movements.reference_id',
                'stock_batches.room_id',
                'stock_batches.unit_cost as batch_cost',
                'stock_batches.received_at',
                'stock_batches.sequence as batch_sequence',
                'products.name as product',
            ])
            /*
             * ⚠️ The order history actually happened in — never by id. A shop
             * catching up on a week of paper enters Thursday after Friday, and
             * an id-ordered replay would call every one of those a finding.
             */
            ->orderBy('stock_movements.occurred_at')
            ->orderBy('stock_movements.sequence')
            ->orderBy('stock_movements.id')
            ->get()
            ->groupBy(fn (object $m) => $m->product_id.':'.$m->room_id);
    }

    /**
     * Walk one product's history in one room, holding what each layer had left.
     *
     * @param  Collection<int, object>  $timeline
     * @return Collection<int, object>
     */
    private function replay(Collection $timeline, ?Carbon $from, ?Carbon $to): Collection
    {
        $left = [];
        $layer = [];
        $findings = collect();

        foreach ($timeline as $movement) {
            $batch = (int) $movement->stock_batch_id;
            $quantity = (int) $movement->quantity;

            // A layer is known from the first movement that touches it, which
            // carries the batch's own date and place in the order.
            $layer[$batch] ??= [
                'received' => Carbon::parse($movement->received_at),
                'sequence' => (int) $movement->batch_sequence,
                // The BATCH's cost, not the movement's — the two agree, and
                // reading it off the layer is the thing that is true by
                // definition rather than by habit.
                'cost' => (int) $movement->batch_cost,
                'id' => $batch,
            ];
            $left[$batch] ??= 0;

            if ($quantity > 0) {
                $left[$batch] += $quantity;

                continue;
            }

            if (in_array($movement->reference_type, self::FIFO_OUT, true)) {
                $older = $this->oldestWithStock($layer, $left, $layer[$batch]);

                if ($older !== null && $this->inWindow($movement, $from, $to)) {
                    $findings->push($this->finding($movement, $layer[$batch], $older, $left[$older['id']]));
                }
            }

            $left[$batch] += $quantity;
        }

        return $findings;
    }

    /**
     * The layer FIFO would have reached for instead, or null when the one it
     * took WAS the oldest with stock.
     *
     * @param  array<int, array<string, mixed>>  $layer
     * @param  array<int, int>  $left
     * @param  array<string, mixed>  $took
     * @return array<string, mixed>|null
     */
    private function oldestWithStock(array $layer, array $left, array $took): ?array
    {
        $older = null;

        foreach ($layer as $id => $candidate) {
            if (($left[$id] ?? 0) < 1) {
                continue;
            }

            if (! $this->comesBefore($candidate, $took)) {
                continue;
            }

            if ($older === null || $this->comesBefore($candidate, $older)) {
                $older = $candidate;
            }
        }

        return $older;
    }

    /** `received_at`, then `sequence`, then `id` — `StockBatch::scopeFifoOrder`. */
    private function comesBefore(array $a, array $b): bool
    {
        return [$a['received']->getPreciseTimestamp(6), $a['sequence'], $a['id']]
            < [$b['received']->getPreciseTimestamp(6), $b['sequence'], $b['id']];
    }

    private function inWindow(object $movement, ?Carbon $from, ?Carbon $to): bool
    {
        $at = Carbon::parse($movement->occurred_at);

        return ($from === null || $at->greaterThanOrEqualTo($from))
            && ($to === null || $at->lessThanOrEqualTo($to));
    }

    /**
     * @param  array<string, mixed>  $took
     * @param  array<string, mixed>  $older
     */
    private function finding(object $movement, array $took, array $older, int $olderLeft): object
    {
        $units = abs((int) $movement->quantity);

        return (object) [
            'movement_id' => (int) $movement->id,
            'occurred_at' => Carbon::parse($movement->occurred_at),
            'reference_type' => $movement->reference_type,
            'reference_id' => (int) $movement->reference_id,
            'product_id' => (int) $movement->product_id,
            'product' => $movement->product,
            'units' => $units,
            'took_batch' => $took['id'],
            'took_cost' => $took['cost'],
            'took_received' => $took['received'],
            'older_batch' => $older['id'],
            'older_cost' => $older['cost'],
            'older_received' => $older['received'],
            'older_left' => $olderLeft,

            // What the wrong layer cost the books. Positive means the sale was
            // charged more than it should have been, so the profit it reported
            // is lower than the truth.
            'difference' => ($took['cost'] - $older['cost']) * $units,
        ];
    }
}
