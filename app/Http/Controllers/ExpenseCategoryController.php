<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Section 4: a managed list, not free text, so the expense report groups
 * cleanly. Deactivating hides a category from new entries but keeps old
 * expenses intact — never delete one that has expenses against it.
 */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request): View
    {
        return view('expense-categories.index', [
            'categories' => ExpenseCategory::withCount('expenses')
                ->orderBy('name')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ExpenseCategory::create([
            ...$request->validate(['name' => ['required', 'string', 'max:255']]),
            'is_active' => true,
        ]);

        return back()->with('success', __('Category saved'));
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $expenseCategory->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', __('Category saved'));
    }

    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        // Never delete a category that has expenses against it — deactivate it
        // so the old entries keep their grouping.
        if ($expenseCategory->expenses()->exists()) {
            $expenseCategory->update(['is_active' => false]);

            return back()->with('success', __('This category has expenses against it, so it was deactivated instead of deleted.'));
        }

        $expenseCategory->delete();

        return back()->with('success', __('Category deleted'));
    }
}
