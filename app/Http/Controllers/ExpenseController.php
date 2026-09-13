<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Rules\Amount;
use App\Services\DocumentNumberService;
use App\Support\MoneyInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function __construct(private DocumentNumberService $numbers) {}

    public function index(Request $request): View
    {
        $expenses = Expense::with('category', 'user')
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->when($request->filled('category'), fn ($q) => $q->where('expense_category_id', $request->input('category')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%'.$request->input('search').'%')
                ->orWhere('document_no', 'like', '%'.$request->input('search').'%')))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        $filtered = Expense::query()
            // The same period the list is showing, or the total would count
            // rows the reader cannot see.
            ->visible($request->boolean('archived'))
            ->when($request->filled('category'), fn ($q) => $q->where('expense_category_id', $request->input('category')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->date('to')));

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) Expense::archivedOnly()->count();

        return view('expenses.index', [
            // Section 2b — which currency this reader types and reads in.
            'lens' => $request->user()->lens(),
            'archivedCount' => $archivedCount,
            'expenses' => $expenses,
            'total' => (int) $filtered->sum('amount'),
            // Section 4: deactivated categories stay available on old expenses
            // but are hidden from new entries.
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
            'allCategories' => ExpenseCategory::orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, Expense $expense): View
    {
        return view('expenses.show', [
            // Section 2b — the edit box on this page takes an amount, so it
            // needs to know which currency it is taking. Passed explicitly, the
            // same as on the list: a partial that looked the preference up
            // itself would give a lens to any screen that included it.
            'lens' => $request->user()->lens(),
            'expense' => $expense->load('category', 'user'),

            // The edit box is on this page as well as the list, and it needs
            // the categories to choose from.
            'categories' => ExpenseCategory::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->rules($request);

        if (books_closed_on($data['expense_date'])) {
            return back()->withInput()->with('error', __('Locked: this date is in a closed period.'));
        }

        DB::transaction(function () use ($data, $request) {
            Expense::create([
                ...$data,
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_EXPENSE),
                'user_id' => $request->user()->id,
            ]);
        });

        return back()->with('success', __('Expense saved'));
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        if (books_closed_on($expense->expense_date)) {
            return back()->with('error', __('Locked: this date is in a closed period.'));
        }

        $expense->update($this->rules($request, $expense));

        return back()->with('success', __('Expense saved'));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        if (books_closed_on($expense->expense_date)) {
            return back()->with('error', __('Locked: this date is in a closed period.'));
        }

        // An expense moves no stock and no balance, so deleting it reverses
        // nothing — the soft delete is the whole reversal.
        $expense->delete();

        return redirect()
            ->to(after_delete(route('expenses.show', $expense), route('expenses.index')))
            ->with('success', __('Expense deleted'));
    }

    /**
     * Section 2b: the amount may have been typed in another currency.
     *
     * ⚠️ Validation reads the string as it was TYPED — `integer` would refuse
     * `12.50` out of a dollar box — and the figure to store is resolved
     * afterwards, because an untouched field keeps what the record already
     * holds rather than the rounding of a conversion. See App\Support\MoneyInput.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Expense $expense = null): array
    {
        $lens = $request->user()->lens();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'amount' => ['required', new Amount($lens, min: 1)],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $data['amount'] = MoneyInput::fromRequest($request, 'amount', $lens, $expense?->amount);

        return $data;
    }
}
