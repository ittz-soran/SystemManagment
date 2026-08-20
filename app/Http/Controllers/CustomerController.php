<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = Customer::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            // The Cash Customer sits first; it is the one Soran reaches for most.
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('customers.index', ['customers' => $customers]);
    }

    public function store(Request $request): RedirectResponse
    {
        Customer::create($this->rules($request));

        return back()->with('success', __('Customer saved'));
    }

    public function show(Customer $customer, Request $request): View
    {
        return view('customers.show', [
            'customer' => $customer,
            'transactions' => AccountTransaction::where('accountable_type', 'customer')
                ->where('accountable_id', $customer->id)
                ->orderByDesc('id')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        // Section 4: the Cash Customer cannot be renamed.
        if ($customer->is_system) {
            return back()->with('error', __('The Cash Customer cannot be renamed.'));
        }

        $customer->update($this->rules($request));

        return back()->with('success', __('Customer saved'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->is_system) {
            return back()->with('error', __('The Cash Customer cannot be deleted.'));
        }

        if ($customer->sales()->exists()) {
            $customer->update(['is_active' => false]);

            return back()->with('success', __('This customer has sales, so they were deactivated instead of deleted.'));
        }

        $customer->delete();

        return back()->with('success', __('Customer deleted'));
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
