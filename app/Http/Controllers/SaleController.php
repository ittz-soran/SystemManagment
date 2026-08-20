<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Sale;
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

    public function show(Sale $sale): View
    {
        return view('sales.show', [
            'sale' => $sale->load('customer', 'user', 'items.product', 'returns'),
            'payments' => $sale->payments()->orderBy('paid_at')->get(),
            'lockState' => $sale->canBeModified(auth()->user()),
        ]);
    }
}
