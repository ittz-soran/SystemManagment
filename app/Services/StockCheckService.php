<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockCheck;
use App\Models\StockCheckLine;
use App\Models\StockMovement;
use App\Models\StockRoom;
use App\Models\User;
use App\Support\Sections;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The stock check — PROJECT_DOC Section 9. Soran, 2026-10-30: *"Show Confirm
 * (mean all correct not need movment), Skip (Show another product), Wrong
 * (mean need correction by ADJ or return)"*.
 *
 * ⚠️ Every answer reads the stock again when it is saved, and refuses if the
 * figure moved since the screen was opened. A count against a figure that has
 * since changed — a sale at the till while the shelf was being counted — is a
 * count of nothing, and an adjustment made from it would put one wrong number
 * right by making it wrong the other way.
 */
class StockCheckService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private StockAdjustmentService $adjustments,
        private ActivityLogger $logger,
    ) {}

    /** Begin a round: what it counts, and the products it walks, in shelf order. */
    public function start(string $scope, ?int $categoryId, ?int $roomId, bool $withEmpty, bool $blind, User $user): StockCheck
    {
        if (! in_array($scope, [StockCheck::SCOPE_ALL, StockCheck::SCOPE_CATEGORY, StockCheck::SCOPE_ROOM], true)) {
            throw new RuntimeException(__('Choose what to count.'));
        }

        if ($scope === StockCheck::SCOPE_CATEGORY && $categoryId === null) {
            throw new RuntimeException(__('Choose the category to count.'));
        }

        if ($scope === StockCheck::SCOPE_ROOM && ($roomId === null || ! Sections::isOn('stock_rooms'))) {
            throw new RuntimeException(__('Choose the stock room to count.'));
        }

        // What each product holds, in one query rather than one per product.
        $held = StockBatch::query()
            ->when($scope === StockCheck::SCOPE_ROOM, fn ($q) => $q->where('room_id', $roomId))
            ->selectRaw('product_id, SUM(quantity_remaining) as held')
            ->groupBy('product_id')
            ->pluck('held', 'product_id');

        $products = Product::query()
            ->where('kind', '!=', Product::KIND_SERVICE)
            ->when($scope === StockCheck::SCOPE_CATEGORY, fn ($q) => $q->where('category_id', $categoryId))
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id'])
            ->filter(fn (Product $product) => $withEmpty || (int) ($held[$product->id] ?? 0) > 0)
            // Category, then name: the order a shop's shelves are most likely
            // in — and natural, so "no.2" comes before "no.10" as on a shelf.
            ->sortBy(fn (Product $product) => mb_strtolower(($product->category?->name ?? '').'|'.$product->name), SORT_NATURAL)
            ->values();

        if ($products->isEmpty()) {
            throw new RuntimeException(__('There is nothing to count there.'));
        }

        return DB::transaction(function () use ($scope, $categoryId, $roomId, $withEmpty, $blind, $user, $products) {
            $check = StockCheck::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_STOCK_CHECK),
                'scope' => $scope,
                'category_id' => $scope === StockCheck::SCOPE_CATEGORY ? $categoryId : null,
                'room_id' => $scope === StockCheck::SCOPE_ROOM ? $roomId : null,
                'with_empty' => $withEmpty,
                'blind' => $blind,
                'started_at' => now(),
                'user_id' => $user->id,
            ]);

            $now = now();
            // A chunk keeps the list's own keys, which are the shelf order.
            foreach ($products->chunk(500) as $chunk) {
                StockCheckLine::insert($chunk->map(fn (Product $product, int $i) => [
                    'stock_check_id' => $check->id,
                    'product_id' => $product->id,
                    'position' => $i + 1,
                    'status' => StockCheckLine::PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            $this->logger->log(
                action: 'create',
                module: 'stock_checks',
                recordId: $check->id,
                description: __('Started stock check :number', ['number' => $check->document_no]),
            );

            return $check;
        });
    }

    /**
     * What the system says this product has, for this round: in its room for a
     * round of one room, and everywhere otherwise. Read from the batches,
     * which are the truth the cached figure is kept from (Section 4).
     */
    public function systemQuantity(StockCheck $check, Product $product): int
    {
        return $this->held($product->id, $check->scope === StockCheck::SCOPE_ROOM ? $check->room_id : null);
    }

    /**
     * The next product to look at: the first not yet looked at, and once they
     * are all done, the skipped ones — longest skipped first, so skipping one
     * again sends it to the back rather than straight back on screen.
     */
    public function next(StockCheck $check): ?StockCheckLine
    {
        return $check->lines()->where('status', StockCheckLine::PENDING)->orderBy('position')->first()
            ?? $check->lines()->where('status', StockCheckLine::SKIPPED)->orderBy('updated_at')->orderBy('position')->first();
    }

    /** Confirm — the shelf agrees with the system. Nothing moves. */
    public function confirm(StockCheckLine $line, int $seen, User $user): StockCheckLine
    {
        return $this->answer($line, $seen, $seen, StockCheckLine::FIX_LATER, null, null, $user);
    }

    /** Skip — not now. It comes back after every product not yet looked at. */
    public function skip(StockCheckLine $line): void
    {
        $this->assertOpen($line);

        if (! in_array($line->status, [StockCheckLine::PENDING, StockCheckLine::SKIPPED], true)) {
            throw new RuntimeException(__('This product has already been answered.'));
        }

        // The time it was skipped orders the skipped ones, so set it even
        // when the status does not change.
        $line->forceFill(['status' => StockCheckLine::SKIPPED, 'updated_at' => now()])->save();
    }

    /**
     * The count, and what to do when it disagrees: make the ADJ now, or put it
     * on the To fix list with a note. A count that agrees is a confirm.
     *
     * @param  string  $fix  StockCheckLine::FIX_ADJUSTED or FIX_LATER
     */
    public function answer(StockCheckLine $line, int $seen, int $counted, string $fix, ?string $note, ?int $unitCost, User $user): StockCheckLine
    {
        $this->assertOpen($line);

        if ($counted < 0) {
            throw new RuntimeException(__('A count cannot be below zero.'));
        }

        if ($line->stock_adjustment_id !== null) {
            throw new RuntimeException(__('This product was already put right by :number.', ['number' => $line->adjustment?->document_no]));
        }

        return DB::transaction(function () use ($line, $seen, $counted, $fix, $note, $unitCost, $user) {
            $line = StockCheckLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $check = $line->stockCheck;
            $product = $line->product;

            $now = $this->systemQuantity($check, $product);

            if ($now !== $seen) {
                throw new RuntimeException(__('The stock of this product changed while it was being counted — it now says :now. Count it again.', ['now' => $now]));
            }

            $agrees = $counted === $now;

            $line->forceFill([
                'status' => $agrees ? StockCheckLine::CONFIRMED : StockCheckLine::WRONG,
                'expected' => $now,
                'counted' => $counted,
                'fix' => $agrees ? null : StockCheckLine::FIX_LATER,
                'note' => $agrees ? null : (trim((string) $note) ?: null),
                'fixed_at' => null,
                'checked_by' => $user->id,
                'checked_at' => now(),
            ])->save();

            if (! $agrees && $fix === StockCheckLine::FIX_ADJUSTED) {
                $this->adjust($line, $unitCost, $user);
            }

            return $line->refresh();
        });
    }

    /**
     * Make the ADJ for a wrong answer — now, or later from the To fix list.
     *
     * ⚠️ Against the main room only: that is the room every adjustment acts
     * on (stock rooms, 2026-09-15). A round of a back room is put right by a
     * move and then an adjustment, so it is refused here, with the reason.
     */
    public function adjust(StockCheckLine $line, ?int $unitCost, User $user): StockAdjustment
    {
        if (! $user->hasPermission('stock_adjustments.create')) {
            throw new RuntimeException(__('Making an adjustment needs the permission to create stock adjustments.'));
        }

        if ($line->status !== StockCheckLine::WRONG || $line->stock_adjustment_id !== null || $line->fix === StockCheckLine::FIX_FIXED) {
            throw new RuntimeException(__('There is nothing left to put right on this product.'));
        }

        $check = $line->stockCheck;

        if ($check->scope === StockCheck::SCOPE_ROOM && $check->room_id !== StockRoom::main()->id) {
            throw new RuntimeException(__('An adjustment acts on the main room. Move the units with a stock move first, then adjust, and mark this fixed.'));
        }

        $difference = $line->difference();
        $product = $line->product;

        return DB::transaction(function () use ($line, $check, $difference, $product, $unitCost, $user) {
            $adjustment = $this->adjustments->create(
                product: $product,
                direction: $difference > 0 ? StockAdjustment::DIRECTION_IN : StockAdjustment::DIRECTION_OUT,
                quantity: abs($difference),
                // ⚠️ The reason is an enum of Section 4's five; MariaDB refuses
                // any other word, where SQLite here stores it. The round's
                // number goes in the notes.
                reason: 'miscount',
                user: $user,
                unitCost: $difference > 0 ? ($unitCost ?? $this->latestCost($product)) : null,
                notes: __('Stock check :number', ['number' => $check->document_no]).($line->note ? ' — '.$line->note : ''),
            );

            $line->forceFill([
                'stock_adjustment_id' => $adjustment->id,
                'fix' => StockCheckLine::FIX_ADJUSTED,
                'fixed_at' => now(),
            ])->save();

            return $adjustment;
        });
    }

    /** Put right some other way — a return, a sale entered late — and say how. */
    public function markFixed(StockCheckLine $line, ?string $note, User $user): void
    {
        if (! $line->isToFix()) {
            throw new RuntimeException(__('There is nothing left to put right on this product.'));
        }

        $note = trim((string) $note);

        $line->forceFill([
            'fix' => StockCheckLine::FIX_FIXED,
            'note' => trim(implode("\n", array_filter([$line->note, $note]))) ?: null,
            'fixed_at' => now(),
        ])->save();

        $this->logger->log(
            action: 'update',
            module: 'stock_checks',
            recordId: $line->stock_check_id,
            description: __('Marked fixed: :product in :number', ['product' => $line->product->name, 'number' => $line->stockCheck->document_no]),
        );
    }

    /** The shop says the round is done. What was never looked at stays "not checked". */
    public function finish(StockCheck $check): void
    {
        if ($check->isFinished()) {
            return;
        }

        $check->forceFill(['finished_at' => now()])->save();

        $this->logger->log(
            action: 'update',
            module: 'stock_checks',
            recordId: $check->id,
            description: __('Finished stock check :number', ['number' => $check->document_no]),
        );
    }

    /**
     * The value at cost of a wrong answer: an ADJ's true FIFO cost when there
     * is one, and otherwise an estimate at the latest batch's cost — which the
     * report says it is.
     *
     * @return array{value: int, estimate: bool}|null
     */
    public function value(StockCheckLine $line): ?array
    {
        $difference = $line->difference();

        if ($difference === null || $difference === 0) {
            return null;
        }

        $adjustment = $line->adjustment;

        if ($adjustment !== null) {
            $value = $adjustment->direction === StockAdjustment::DIRECTION_IN
                ? (int) $adjustment->quantity * (int) $adjustment->unit_cost
                : (int) StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
                    ->where('reference_id', $adjustment->id)
                    ->selectRaw('SUM(-('.StockMovement::VALUE.')) as v')->value('v');

            return ['value' => $difference > 0 ? $value : -$value, 'estimate' => false];
        }

        return ['value' => $difference * $this->latestCost($line->product), 'estimate' => true];
    }

    /** The cost of the most recent layer this product received, or its purchase price. */
    public function latestCost(Product $product): int
    {
        $cost = StockBatch::where('product_id', $product->id)
            ->orderByDesc('received_at')->orderByDesc('sequence')->orderByDesc('id')
            ->value('unit_cost');

        return (int) ($cost ?? $product->purchase_price ?? 0);
    }

    private function held(int $productId, ?int $roomId): int
    {
        return (int) StockBatch::where('product_id', $productId)
            ->when($roomId !== null, fn ($q) => $q->where('room_id', $roomId))
            ->sum('quantity_remaining');
    }

    private function assertOpen(StockCheckLine $line): void
    {
        if ($line->stockCheck->isFinished()) {
            throw new RuntimeException(__('This stock check is finished.'));
        }
    }
}
