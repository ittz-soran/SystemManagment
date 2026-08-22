<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\DocumentNumberService;
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
            'archivedCount' => $archivedCount,
            'expenses' => $expenses,
            'total' => (int) $filtered->sum('amount'),
            // Section 4: deactivated categories stay available on old expenses
            // but are hidden from new entries.
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
            'allCategories' => ExpenseCategory::orderBy('name')->get(),
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

        $expense->update($this->rules($request));

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

        return back()->with('success', __('Expense deleted'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
