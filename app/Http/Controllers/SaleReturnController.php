<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * Section 7: partial line, whole line, or whole sale — one form, one mechanism.
 *
 * Returns are never blocked by the edit lock. A return creates a new forward
 * document; it doesn't rewrite history.
 */
class SaleReturnController extends Controller
{
    public function __construct(private SaleReturnService $returns) {}

    public function index(Request $request): View
    {
        $returns = SaleReturn::with('sale', 'customer', 'user')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->when($request->filled('search'), fn ($q) => $q->where('document_no', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('return_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('return_date', '<=', $request->date('to')))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('sale-returns.index', ['returns' => $returns]);
    }

    /** The return screen for one sale. */
    public function create(Sale $sale): View
    {
        return view('sale-returns.create', [
            'sale' => $sale->load('customer', 'items.product'),
        ]);
    }

    public function store(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'lines' => ['required', 'array'],
            'lines.*.sale_item_id' => ['required', 'exists:sale_items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $return = $this->returns->create(
                sale: $sale,
                lines: $data['lines'],
                user: $request->user(),
                returnDate: Carbon::parse($data['return_date']),
                reason: $data['reason'] ?? null,
                paymentMethod: $data['payment_method'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sale-returns.show', $return)
            ->with('success', __('Return saved'));
    }

    public function show(SaleReturn $saleReturn): View
    {
        return view('sale-returns.show', [
            'return' => $saleReturn->load('sale', 'customer', 'user', 'items.product'),
            'payments' => $saleReturn->payments()->orderBy('paid_at')->get(),
        ]);
    }

    /**
     * Section 5: deleting a return takes its movements, subtracts each from its
     * batch and deletes them — the reverses_movement_id links restore the earlier
     * state exactly, with no recomputation.
     */
    public function destroy(Request $request, SaleReturn $saleReturn): RedirectResponse
    {
        $sale = $saleReturn->sale;

        try {
            $this->returns->delete($saleReturn, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', __('Return deleted and its stock put back'));
    }
}
