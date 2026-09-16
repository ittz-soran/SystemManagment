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
            'stock' => $this->whatIsIn($from),
        ]);
    }

    /** What one room holds, for the picker. Asked again when the room changes. */
    public function stock(Request $request, StockRoom $stockRoom)
    {
        return response()->json($this->whatIsIn($stockRoom->id)->values());
    }

    public function store(Request $request): RedirectResponse
    {
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
    private function whatIsIn(int $roomId)
    {
        return Product::query()
            ->join('stock_batches as b', 'b.product_id', '=', 'products.id')
            ->where('b.room_id', $roomId)
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.unit')
            ->havingRaw('SUM(b.quantity_remaining) > 0')
            ->select('products.id', 'products.name', 'products.sku', 'products.unit')
            ->selectRaw('SUM(b.quantity_remaining) as units')
            ->orderBy('products.name')
            ->get();
    }
}
