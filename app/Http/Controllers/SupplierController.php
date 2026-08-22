<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $suppliers = Supplier::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('suppliers.index', ['suppliers' => $suppliers]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->rules($request));

        return back()->with('success', __('Supplier saved'));
    }

    public function show(Supplier $supplier, Request $request): View
    {
        // Section 9: a balance statement, read from the ledger rather than the
        // cached balance column.
        return view('suppliers.show', [
            'supplier' => $supplier,
            'transactions' => AccountTransaction::with('reference')
                ->where('accountable_type', 'supplier')
                ->where('accountable_id', $supplier->id)
                ->orderByDesc('id')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->rules($request));

        return back()->with('success', __('Supplier saved'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        // Section 5: suppliers with history are deactivated, never deleted.
        if ($supplier->purchases()->exists()) {
            $supplier->update(['is_active' => false]);

            return back()->with('success', __('This supplier has purchases, so it was deactivated instead of deleted.'));
        }

        $supplier->delete();

        return back()->with('success', __('Supplier deleted'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
