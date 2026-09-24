<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\Product;
use App\Services\AssemblyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * One screen, both directions — Soran, 2026-09-24.
 *
 * *"I purchased second hand ps5 slim digital have box and 2 controller ... but
 * customer say need 1 controller!!"*, and *"this cases repeat daily in gaming
 * pc build"*.
 *
 * ⚠️ Taking apart and putting together are the same document read from
 * different ends, so they are the same form with the sides swapped rather than
 * two screens that would drift apart.
 */
class AssemblyController extends Controller
{
    public function __construct(private AssemblyService $assemblies) {}

    public function index(Request $request): View
    {
        return view('assemblies.index', [
            'lens' => $request->user()->lens(),
            'assemblies' => Assembly::with('user', 'items.product')
                ->orderByDesc('assembled_at')->orderByDesc('id')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    public function create(Request $request): View
    {
        $direction = in_array($request->string('direction')->toString(), Assembly::DIRECTIONS, true)
            ? $request->string('direction')->toString()
            : Assembly::APART;

        return view('assemblies.create', [
            'lens' => $request->user()->lens(),
            'direction' => $direction,

            /*
             * Everything with something on the shelf, because that is all that
             * can go INTO one of these. A service holds no stock and a product
             * with none cannot be taken apart or built from.
             */
            /*
             * ⚠️ With their batches, in FIFO order, so the screen can work out
             * what a chosen quantity will ACTUALLY cost and show the remainder
             * live. A guess from `purchase_price` would be a different number
             * from the one the service books, and the balance check would then
             * refuse a form that looked right.
             */
            'available' => Product::where('kind', '!=', Product::KIND_SERVICE)
                ->where('quantity', '>', 0)
                ->with(['stockBatches' => fn ($q) => $q->withStock()->fifoOrder()])
                ->orderBy('name')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'unit' => $p->unit,
                    'quantity' => $p->quantity,
                    'batches' => $p->stockBatches
                        ->map(fn ($b) => ['cost' => (int) $b->unit_cost, 'left' => (int) $b->quantity_remaining])
                        ->values(),
                ])
                ->values(),

            // And everything that a piece could land in, stock or second-hand.
            'products' => Product::where('kind', '!=', Product::KIND_SERVICE)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(Assembly::DIRECTIONS)],
            'note' => ['nullable', 'string', 'max:500'],

            'whole' => ['required', 'array'],
            'whole.product_id' => ['nullable', 'exists:products,id'],
            'whole.name' => ['nullable', 'string', 'max:255'],
            'whole.quantity' => ['required', 'integer', 'min:1'],
            'whole.sale_price' => ['nullable', 'integer', 'min:0'],

            'pieces' => ['required', 'array', 'min:1'],
            'pieces.*.product_id' => ['nullable', 'exists:products,id'],
            'pieces.*.name' => ['nullable', 'string', 'max:255'],
            'pieces.*.quantity' => ['required', 'integer', 'min:1'],
            'pieces.*.unit_cost' => ['nullable', 'integer', 'min:0'],
            'pieces.*.sale_price' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $assembly = $data['direction'] === Assembly::APART
                ? $this->assemblies->takeApart(
                    whole: $data['whole'],
                    pieces: $data['pieces'],
                    user: $request->user(),
                    note: $data['note'] ?? null,
                )
                : $this->assemblies->putTogether(
                    pieces: $data['pieces'],
                    whole: $data['whole'],
                    user: $request->user(),
                    note: $data['note'] ?? null,
                );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('assemblies.show', $assembly)
            ->with('success', __('Saved as :number', ['number' => $assembly->document_no]));
    }

    /**
     * Share a total out between the pieces, by what each will sell for.
     *
     * ⚠️ A round trip for a button, deliberately. The remainder has to land
     * somewhere or the document will not balance, and WHERE it lands is a real
     * decision with a test behind it — see AssemblyService::shareOut. A second
     * copy of that in JavaScript would be the arithmetic about money written
     * twice, which is how two answers come to disagree.
     */
    public function share(Request $request): JsonResponse
    {
        $data = $request->validate([
            'total' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.value' => ['required', 'integer', 'min:0'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        // Costs per unit, ready to drop straight into the boxes.
        return response()->json([
            'costs' => $this->assemblies
                ->shareOut(collect($data['lines']), (int) $data['total'])
                ->values(),
        ]);
    }

    public function show(Request $request, Assembly $assembly): View
    {
        return view('assemblies.show', [
            'lens' => $request->user()->lens(),
            'assembly' => $assembly->load('items.product', 'user'),
        ]);
    }
}
