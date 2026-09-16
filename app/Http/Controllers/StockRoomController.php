<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockRoom;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The rooms a shop keeps stock in — Soran, 2026-09-15.
 *
 * ⚠️ **The main room is not editable into something else.** It can be renamed;
 * it cannot be closed, deleted, or demoted, and no other room can be promoted
 * over it. Every sale in the shop draws from it, so a screen that let somebody
 * take it away would be a screen that could stop the till.
 */
class StockRoomController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function index(Request $request): View
    {
        $rooms = StockRoom::query()->inOrder()->get();

        /*
         * What each room holds, in one query rather than one per room.
         *
         * Units and distinct products, because they answer different questions:
         * "how full is the back room" and "how many different things am I
         * looking for when I go out there".
         */
        $held = StockBatch::query()
            ->selectRaw('room_id, SUM(quantity_remaining) as units, COUNT(DISTINCT product_id) as lines')
            ->where('quantity_remaining', '>', 0)
            ->groupBy('room_id')
            ->get()
            ->keyBy('room_id');

        return view('stock-rooms.index', [
            'rooms' => $rooms,
            'held' => $held,
            'canManage' => $request->user()->hasPermission('stock_rooms.manage'),
        ]);
    }

    /** What one room is holding, product by product. */
    public function show(Request $request, StockRoom $stockRoom): View
    {
        $lines = Product::query()
            ->join('stock_batches as b', 'b.product_id', '=', 'products.id')
            ->where('b.room_id', $stockRoom->id)
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.unit')
            ->havingRaw('SUM(b.quantity_remaining) > 0')
            ->select('products.id', 'products.name', 'products.sku', 'products.unit')
            ->selectRaw('SUM(b.quantity_remaining) as units')
            ->orderBy('products.name')
            ->paginate($request->user()->items_per_page);

        return view('stock-rooms.show', [
            'room' => $stockRoom,
            'lines' => $lines,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $room = StockRoom::create([...$data, 'is_main' => false]);

        $this->log->log('create', 'stock_rooms', $room->id, __('Added stock room :name', ['name' => $room->name]), user: $request->user());

        return redirect()->route('stock-rooms.index')->with('success', __('Stock room added'));
    }

    public function update(Request $request, StockRoom $stockRoom): RedirectResponse
    {
        $data = $this->validated($request, $stockRoom);

        /*
         * ⚠️ The main room stays open whatever the form says. It is the room
         * every sale draws from, and "closed" means nothing can be moved in —
         * which for this room would mean purchases had nowhere to land.
         */
        if ($stockRoom->is_main) {
            $data['is_active'] = true;
        }

        $before = $stockRoom->only(['name', 'is_active', 'note', 'sort_order']);

        $stockRoom->forceFill($data)->save();

        $this->log->log('update', 'stock_rooms', $stockRoom->id, __('Changed stock room :name', ['name' => $stockRoom->name]), $before, $request->user());

        return redirect()->route('stock-rooms.index')->with('success', __('Stock room saved'));
    }

    /**
     * Remove a room, but only one that is empty and is not the till's.
     *
     * ⚠️ Refused rather than cascaded. A room holding stock is stock the shop
     * owns, and deleting the room would either orphan those layers or silently
     * move them somewhere nobody chose. Emptying it first is one transfer, and
     * it is the shopkeeper who decides where the goods go.
     */
    public function destroy(Request $request, StockRoom $stockRoom): RedirectResponse
    {
        if ($stockRoom->is_main) {
            return back()->with('error', __('The main room cannot be removed — every sale draws from it.'));
        }

        $held = DB::table('stock_batches')->where('room_id', $stockRoom->id)->sum('quantity_remaining');

        if ($held > 0) {
            return back()->with('error', __('There are still :count units in :room. Move them somewhere else first.', [
                'count' => number_format((int) $held),
                'room' => $stockRoom->name,
            ]));
        }

        $this->log->log('delete', 'stock_rooms', $stockRoom->id, __('Removed stock room :name', ['name' => $stockRoom->name]), user: $request->user());

        $stockRoom->delete();

        return redirect()->route('stock-rooms.index')->with('success', __('Stock room removed'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?StockRoom $room): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('stock_rooms', 'name')->ignore($room?->id)->whereNull('deleted_at'),
            ],
            'note' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
