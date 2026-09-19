<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockRoom;
use App\Models\StockTransfer;
use App\Services\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Moving goods between rooms — Soran, 2026-09-15.
 *
 * ⚠️ **The screen offers only what the "from" room actually holds.** A list of
 * every product in the catalogue would let somebody fill a transfer with things
 * that are not in that room, and find out one line at a time when it refuses.
 * The engine refuses either way; this is about not wasting somebody's morning.
 */
class StockTransferController extends Controller
{
    /** Enough to choose from, few enough to read without scrolling. */
    private const RESULTS = 8;

    public function __construct(private readonly TransferService $transfers) {}

    public function index(Request $request): View
    {
        $transfers = StockTransfer::query()
            ->with(['fromRoom', 'toRoom', 'user', 'items'])
            ->when($request->filled('room'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('from_room_id', $request->integer('room'))
                    ->orWhere('to_room_id', $request->integer('room'));
            }))
            ->orderByDesc('id')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('stock-transfers.index', [
            'transfers' => $transfers,
            'rooms' => StockRoom::query()->inOrder()->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $rooms = StockRoom::query()->inOrder()->get();

        // Defaults that match what a shop does most: goods come in, and go out
        // the back.
        $from = $request->integer('from') ?: StockRoom::main()->id;

        return view('stock-transfers.create', [
            'rooms' => $rooms,
            'fromId' => $from,
        ]);
    }

    /**
     * What one room holds, searched — Soran, 2026-09-18: *"change move
     * mechanism to same as sale/purchase to search products, show cart"*.
     *
     * ⚠️ **Searched within the ROOM, not across the catalogue.** A transfer can
     * only carry what is actually on that shelf, so offering a product the room
     * does not hold would be offering a line the engine is going to refuse. The
     * quantity beside each name is what that room has, which is the number the
     * person is deciding against.
     */
    public function stock(Request $request, StockRoom $stockRoom)
    {
        $term = $request->string('q')->trim()->toString();

        $matches = $this->whatIsIn($stockRoom->id, $term === '' ? null : $term);

        /*
         * A scan is a whole code and means one product, so it is added rather
         * than offered — the same rule the sale and purchase carts follow, and
         * the reason a barcode gun works on this screen at all.
         */
        $exact = $term !== '' && $matches->count() === 1 && (
            strcasecmp((string) $matches->first()->sku, $term) === 0
            || strcasecmp((string) $matches->first()->barcode, $term) === 0
        );

        return response()->json([
            'products' => $matches->take(self::RESULTS)->values(),
            'exact' => $exact,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * ⚠️ **A row nobody typed in is not a mistake — Soran, 2026-09-18.**
         *
         * This screen is not a cart you add to; it lists EVERY product the room
         * holds, with a quantity box on each, and posts all of them. Type 3
         * against one product in a room holding 306 and the other 305 arrive as
         * zero — so `min:1` on every row answered with 305 error messages:
         *
         *     The lines.1.quantity field must be at least 1.
         *     The lines.2.quantity field must be at least 1.
         *     … 303 more …
         *
         * with the one real instruction nowhere in sight. A blank box means "not
         * this one", which is the ordinary way to use a list like this, so the
         * empty rows are dropped before anything is judged. What is left is
         * what the shopkeeper actually asked to move, and `lines` being empty
         * after that has one honest sentence of its own.
         *
         * The rule below stays as the backstop: a row that survives this and
         * still says zero was not typed by this form.
         */
        $request->merge([
            'lines' => collect((array) $request->input('lines', []))
                ->filter(fn ($line) => is_array($line) && (int) ($line['quantity'] ?? 0) > 0)
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'from_room_id' => ['required', Rule::exists('stock_rooms', 'id')->whereNull('deleted_at')],
            'to_room_id' => ['required', 'different:from_room_id', Rule::exists('stock_rooms', 'id')->whereNull('deleted_at')],
            'transferred_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'to_room_id.different' => __('Choose two different rooms.'),
            'lines.required' => __('Type how many to move against at least one product.'),
            'lines.min' => __('Type how many to move against at least one product.'),
        ]);

        try {
            $transfer = $this->transfers->create(
                from: StockRoom::findOrFail($data['from_room_id']),
                to: StockRoom::findOrFail($data['to_room_id']),
                lines: $data['lines'],
                transferredAt: Carbon::parse($data['transferred_at']),
                note: $data['note'] ?? null,
                user: $request->user(),
            );
        } catch (Throwable $e) {
            /*
             * ⚠️ Back to the form with what they typed, not a 500.
             *
             * Every refusal here is a true sentence about the shop's stock —
             * not enough in that room, a room that is closed — and the person
             * reading it has a cart half filled in. Losing it would make them
             * type the whole thing again to see the same message.
             */
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $transfer)
            ->with('success', __('Stock moved'));
    }

    public function show(StockTransfer $stockTransfer): View
    {
        return view('stock-transfers.show', [
            'transfer' => $stockTransfer->load(['fromRoom', 'toRoom', 'user', 'items.product']),
        ]);
    }

    public function destroy(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        try {
            $this->transfers->delete($stockTransfer, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.index')->with('success', __('Stock move undone'));
    }

    /**
     * Everything one room is holding, as {id, name, sku, unit, units}.
     *
     * @return Collection<int, object>
     */
    private function whatIsIn(int $roomId, ?string $term = null)
    {
        return Product::query()
            ->join('stock_batches as b', 'b.product_id', '=', 'products.id')
            ->where('b.room_id', $roomId)
            /*
             * ⚠️ The three grouped together, not chained loose. An ungrouped
             * `orWhere` escapes the room filter above it and the screen would
             * offer stock from a room this transfer is not leaving from — see
             * ProductController::search, where the same mistake was made once.
             */
            ->when($term !== null, fn ($q) => $q->where(fn ($q) => $q
                ->where('products.name', 'like', '%'.$term.'%')
                ->orWhere('products.sku', 'like', '%'.$term.'%')
                ->orWhere('products.barcode', 'like', '%'.$term.'%')))
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.barcode', 'products.unit')
            ->havingRaw('SUM(b.quantity_remaining) > 0')
            ->select('products.id', 'products.name', 'products.sku', 'products.barcode', 'products.unit')
            ->selectRaw('SUM(b.quantity_remaining) as units')
            ->orderBy('products.name')
            ->get();
    }
}
