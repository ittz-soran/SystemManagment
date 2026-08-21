<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\BulkDeleteService;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales) {}

    public function index(Request $request): View
    {
        $sales = Sale::with('customer', 'user')
            // Section 9b: sort the newest first on every transactional list.
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sale_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sale_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where('document_no', 'like', '%'.$request->input('search').'%'))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'customers' => Customer::orderByDesc('is_system')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('sales.create', [
            // Section 4: the Cash Customer is the default for walk-in buyers.
            'customers' => Customer::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'cashCustomer' => Customer::cashCustomer(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'sale_date' => ['required', 'date'],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            // Section 2: IQD is whole numbers only.
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $sale = $this->sales->create(
                customer: Customer::findOrFail($data['customer_id']),
                lines: $data['lines'],
                user: $request->user(),
                saleDate: \Illuminate\Support\Carbon::parse($data['sale_date']),
                amountPaid: (int) ($data['amount_paid'] ?? 0),
                paymentMethod: $data['payment_method'],
            );
        } catch (InsufficientStockException $e) {
            // Section 10b T8: nothing is written and no document number is
            // consumed, because the counter increments inside the same
            // transaction that just rolled back.
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', __('Sale saved'));
    }

    /**
     * Section 7: "Make return the obvious action on the sale page; keep edit
     * tucked away." Using edit when goods came back erases information you would
     * want later, like which customers return often.
     */
    public function edit(Sale $sale): View|RedirectResponse
    {
        $lock = $sale->canBeModified(auth()->user());

        if (! $lock['allowed']) {
            return redirect()->route('sales.show', $sale)->with('error', $lock['reason']);
        }

        $sale->load('items.product');

        return view('sales.edit', [
            'sale' => $sale,
            'cartLines' => $this->cartLines($sale),
            'customers' => Customer::where('is_active', true)
                ->orderByDesc('is_system')->orderBy('name')->get(),
            'cashCustomer' => Customer::cashCustomer(),
        ]);
    }

    /**
     * The cart screen's line shape, filled in from a saved sale.
     *
     * Stock is shown as what the edit may actually use: the update reverses
     * this sale before re-running FIFO, so the units already on the sale are
     * available to it again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartLines(Sale $sale): array
    {
        $onSale = $sale->items->groupBy('product_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));

        return $sale->items->map(function ($item) use ($onSale) {
            $cost = $this->sales->nextBatchCost($item->product);

            return [
                'id' => $item->product_id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'quantity' => $item->quantity,
                'price' => $item->unit_price,
                'stock' => $item->product->quantity + $onSale[$item->product_id],
                'cost' => $cost,
                'belowCost' => $cost !== null && $item->unit_price < $cost,
            ];
        })->values()->all();
    }

    public function update(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'sale_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->sales->update(
                sale: $sale,
                customer: Customer::findOrFail($data['customer_id']),
                lines: $data['lines'],
                user: $request->user(),
                saleDate: \Illuminate\Support\Carbon::parse($data['sale_date']),
            );
        } catch (InsufficientStockException|\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', __('Sale saved'));
    }

    public function destroy(Request $request, Sale $sale): RedirectResponse
    {
        try {
            $this->sales->delete($sale, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.index')->with('success', __('Sale deleted and its stock put back'));
    }

    public function show(Sale $sale): View
    {
        return view('sales.show', [
            'sale' => $sale->load('customer', 'user', 'items.product', 'returns'),
            'payments' => $sale->payments()->orderBy('paid_at')->get(),
            'lockState' => $sale->canBeModified(auth()->user()),
        ]);
    }

    /**
     * Section 8b: a loop of the normal single-delete logic. Locked rows are
     * skipped and reported rather than failing the whole batch.
     */
    public function bulkDestroy(Request $request, BulkDeleteService $bulk): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:sales,id'],
        ]);

        $result = $bulk->run(
            models: Sale::whereIn('id', $data['ids'])->orderByDesc('id')->get(),
            delete: fn (Sale $sale) => $this->sales->delete($sale, $request->user()),
            guard: fn (Sale $sale) => $sale->canBeModified($request->user()),
        );

        return back()->with(
            $result['deleted'] > 0 ? 'success' : 'error',
            $bulk->summarise($result),
        );
    }
}
