<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\LedgerService;
use App\Services\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Section 4: manual +/- with a reason, and the ONLY way to correct a locked
 * document. Adjustments never touch supplier or customer balances — stock
 * moves, money does not.
 */
class StockAdjustmentController extends Controller
{
    /** Exactly the list in Section 4. */
    public const REASONS = ['damage', 'theft', 'miscount', 'correction', 'other'];

    public function __construct(
        private StockAdjustmentService $adjustments,
        private LedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        $adjustments = StockAdjustment::with('product', 'user')
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            ->orderByDesc('adjusted_at')
            ->orderByDesc('id')
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->input('reason')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->input('product_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('document_no', 'like', '%'.$request->input('search').'%'))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) StockAdjustment::archivedOnly()->count();

        return view('stock-adjustments.index', [
            'archivedCount' => $archivedCount,
            'adjustments' => $adjustments,
            'reasons' => self::REASONS,

            // Arrived from a product's own page, which is where the shopkeeper
            // noticed the shelf and the screen disagreeing. The form opens with
            // that product already chosen rather than asking them to find it
            // again in a box they have just come from.
            'startWith' => $request->filled('product')
                ? Product::ofKind([Product::KIND_STOCK, Product::KIND_USED])->find($request->integer('product'))
                : null,
        ]);
    }

    /**
     * Section 4: an adjustment is the only way to correct a locked document, so
     * what it did to the batches is the whole point of reading one. The batch it
     * created, or the batches it drew down, are shown with it.
     */
    public function show(StockAdjustment $stockAdjustment): View
    {
        $stockAdjustment->load('product', 'user');

        return view('stock-adjustments.show', [
            'adjustment' => $stockAdjustment,
            'movements' => StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
                ->where('reference_id', $stockAdjustment->id)
                ->with('batch')
                ->orderBy('occurred_at')
                ->orderBy('sequence')
                ->get(),
            'batch' => StockBatch::where('source_type', StockBatch::SOURCE_ADJUSTMENT)
                ->where('source_id', $stockAdjustment->id)
                ->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'direction' => ['required', Rule::in([StockAdjustment::DIRECTION_IN, StockAdjustment::DIRECTION_OUT])],
            'quantity' => ['required', 'integer', 'min:1'],
            // Section 4: required for `in`, ignored for `out` — FIFO needs a cost
            // for every unit coming in, and the cost of units going out comes
            // from the batches they are drawn from.
            'unit_cost' => ['nullable', 'integer', 'min:0', 'required_if:direction,in'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
            'adjusted_at' => ['required', 'date'],
        ], [
            'unit_cost.required_if' => __('An incoming adjustment needs a unit cost — FIFO needs a cost for every unit.'),
        ]);

        try {
            $this->adjustments->create(
                product: Product::findOrFail($data['product_id']),
                direction: $data['direction'],
                quantity: (int) $data['quantity'],
                reason: $data['reason'],
                user: $request->user(),
                unitCost: $data['unit_cost'] ?? null,
                notes: $data['notes'] ?? null,
                adjustedAt: Carbon::parse($data['adjusted_at']),
            );
        } catch (InsufficientStockException|RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Adjustment saved'));
    }

    public function edit(StockAdjustment $stockAdjustment): View
    {
        return view('stock-adjustments.edit', [
            'adjustment' => $stockAdjustment->load('product'),
            'reasons' => self::REASONS,
        ]);
    }

    /**
     * Section 8's shape for an edit: reverse and re-apply. The service does the
     * whole of it in one transaction, and refuses on the same terms a delete
     * does — an incoming adjustment whose units have been sold cannot be
     * unwound, because those units are on a customer's invoice.
     */
    public function update(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in([StockAdjustment::DIRECTION_IN, StockAdjustment::DIRECTION_OUT])],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'integer', 'min:0', 'required_if:direction,in'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
            'adjusted_at' => ['required', 'date'],
        ], [
            'unit_cost.required_if' => __('An incoming adjustment needs a unit cost — FIFO needs a cost for every unit.'),
        ]);

        try {
            $this->adjustments->update(
                adjustment: $stockAdjustment,
                direction: $data['direction'],
                quantity: (int) $data['quantity'],
                reason: $data['reason'],
                user: $request->user(),
                unitCost: $data['unit_cost'] ?? null,
                notes: $data['notes'] ?? null,
                adjustedAt: Carbon::parse($data['adjusted_at']),
            );
        } catch (InsufficientStockException|RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock-adjustments.show', $stockAdjustment)
            ->with('success', __('Adjustment saved'));
    }

    public function destroy(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        try {
            $this->adjustments->delete($stockAdjustment, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // An adjustment is always a note about one product's shelf, so that
        // product's page is a better last resort than the list: it is where the
        // reader was looking when the shelf and the screen disagreed, and it is
        // certain to still be there.
        $product = $stockAdjustment->product()->withTrashed()->first();

        return redirect()
            ->to(after_delete(
                route('stock-adjustments.show', $stockAdjustment),
                $product && ! $product->trashed()
                    ? route('products.show', $product)
                    : route('stock-adjustments.index'),
            ))
            ->with('success', __('Adjustment deleted'));
    }

    /**
     * Section 4: the admin "Recheck stock" action. Compares every product's
     * cached value against its batch sum and lists mismatches.
     *
     * "If they ever differ, the batches win."
     */
    public function recheckStock(): View
    {
        $mismatches = [];

        foreach (Product::withTrashed()->cursor() as $product) {
            $batchSum = (int) $product->stockBatches()->sum('quantity_remaining');
            $movementSum = (int) $product->stockMovements()->sum('quantity');

            if ($product->quantity !== $batchSum || $batchSum !== $movementSum) {
                $mismatches[] = [
                    'product' => $product,
                    'cached' => (int) $product->quantity,
                    'batches' => $batchSum,
                    'movements' => $movementSum,
                ];
            }
        }

        return view('stock-adjustments.recheck', ['mismatches' => $mismatches]);
    }

    /** Rewrites every cached quantity from its batches. The batches win. */
    public function repairStock(): RedirectResponse
    {
        $fixed = DB::transaction(function () {
            $count = 0;

            foreach (Product::withTrashed()->cursor() as $product) {
                $batchSum = (int) $product->stockBatches()->sum('quantity_remaining');

                if ($product->quantity !== $batchSum) {
                    $product->forceFill(['quantity' => $batchSum])->save();
                    $count++;
                }
            }

            return $count;
        });

        return back()->with('success', trans_choice(
            '{0}Nothing needed fixing|{1}:count product corrected from its batches|[2,*]:count products corrected from their batches',
            $fixed,
            ['count' => $fixed],
        ));
    }

    /**
     * Section 4: the admin "recalculate balances" action. The ledger is the
     * truth; the balance columns are caches of the latest balance_after.
     */
    public function recalculateBalances(): RedirectResponse
    {
        $mismatches = DB::transaction(fn () => $this->ledger->recalculateBalances());

        return back()->with('success', trans_choice(
            '{0}Every balance already matched its ledger|{1}:count balance corrected from the ledger|[2,*]:count balances corrected from the ledger',
            count($mismatches),
            ['count' => count($mismatches)],
        ));
    }
}
