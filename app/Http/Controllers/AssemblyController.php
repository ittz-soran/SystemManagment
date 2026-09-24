<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AssemblyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            'available' => $this->onTheShelf(),

            // And everything that a piece could land in, stock or second-hand.
            'products' => Product::where('kind', '!=', Product::KIND_SERVICE)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit']),
        ]);
    }

    /**
     * ⚠️ One set of rules for saving and for editing, so the two cannot come
     * to disagree about what a line may contain.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $editing = false): array
    {
        return $request->validate([
            'direction' => [$editing ? 'nullable' : 'required', Rule::in(Assembly::DIRECTIONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'assembled_at' => [$editing ? 'required' : 'nullable', 'date'],

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
    }

    public function edit(Request $request, Assembly $assembly): View
    {
        $assembly->load('items.product');

        return view('assemblies.create', [
            'lens' => $request->user()->lens(),
            'direction' => $assembly->direction,
            'assembly' => $assembly,

            // ⚠️ What this document itself consumed counts as available to it:
            // an edit puts the shelf back first, so the thing it was made of is
            // there to be chosen again even though the shelf says nothing today.
            'available' => $this->onTheShelf($assembly),
            'products' => Product::where('kind', '!=', Product::KIND_SERVICE)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit']),
        ]);
    }

    public function update(Request $request, Assembly $assembly): RedirectResponse
    {
        $data = $this->validated($request, editing: true);

        try {
            [$sources, $results] = $assembly->isApart()
                ? [[$data['whole']], $data['pieces']]
                : [$data['pieces'], [$data['whole']]];

            $this->assemblies->update(
                assembly: $assembly,
                sources: $sources,
                results: $results,
                user: $request->user(),
                at: Carbon::parse($data['assembled_at']),
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('assemblies.show', $assembly)
            ->with('success', __('Saved as :number', ['number' => $assembly->document_no]));
    }

    public function destroy(Request $request, Assembly $assembly): RedirectResponse
    {
        try {
            $this->assemblies->delete($assembly, $request->user());
        } catch (RuntimeException|Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('assemblies.index')
            ->with('success', __(':number deleted', ['number' => $assembly->document_no]));
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

    /**
     * Everything with something on the shelf, which is all that can go into one
     * of these.
     *
     * ⚠️ When editing, what THIS document consumed counts too. The edit puts
     * the shelf back before applying the new figures, so the bundle it was made
     * of is available to it again — and without this the form would open with
     * its own source missing from the list and no way to say so.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function onTheShelf(?Assembly $editing = null)
    {
        /*
         * ⚠️ **What this document itself consumed is put back first.**
         *
         * An edit unwinds before it re-applies, so the bundle it was made of is
         * available to it again — but the shelf today says zero and its batch
         * holds nothing, because this very document emptied it. Without adding
         * those units back here, the form opens with its own source reading no
         * cost at all: the remainder never reaches zero and the save button
         * never lights. Found by editing one in a browser, not by reading this.
         *
         * Read off the movements, which say exactly which batches gave up how
         * many — no guessing, and the same numbers the unwind will restore.
         */
        $restore = $editing === null
            ? collect()
            : StockMovement::where('reference_type', StockMovement::REF_ASSEMBLY)
                ->where('reference_id', $editing->id)
                ->where('quantity', '<', 0)
                ->get()
                ->groupBy('stock_batch_id')
                ->map(fn ($group) => (int) abs($group->sum('quantity')));

        $alsoInclude = $editing === null
            ? []
            : $editing->sourceLines()->pluck('product_id')->all();

        return Product::where('kind', '!=', Product::KIND_SERVICE)
            ->where(fn ($q) => $q->where('quantity', '>', 0)->orWhereIn('id', $alsoInclude))
            // ⚠️ Not `withStock()` while editing: a batch emptied by this very
            // document has to be in the list so its units can be added back.
            ->with(['stockBatches' => fn ($q) => $editing === null ? $q->withStock()->fifoOrder() : $q->fifoOrder()])
            ->orderBy('name')
            ->get()
            ->map(function (Product $p) use ($restore) {
                $batches = $p->stockBatches
                    ->map(fn ($b) => [
                        'cost' => (int) $b->unit_cost,
                        'left' => (int) $b->quantity_remaining + (int) ($restore[$b->id] ?? 0),
                    ])
                    ->filter(fn (array $b) => $b['left'] > 0)
                    ->values();

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'unit' => $p->unit,
                    // What it will have once the unwind has run, which is what
                    // the reader is choosing from.
                    'quantity' => $batches->sum('left'),
                    'batches' => $batches,
                ];
            })
            ->values();
    }

    public function show(Request $request, Assembly $assembly): View
    {
        return view('assemblies.show', [
            'lens' => $request->user()->lens(),
            'assembly' => $assembly->load('items.product', 'user'),

            // Section 8: computed live, so the button says why rather than
            // failing when it is pressed.
            'deleteState' => $assembly->canBeDeleted($request->user()),
        ]);
    }
}
