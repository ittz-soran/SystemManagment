<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        // Counted by kind, because the count has to lead somewhere. Products,
        // second-hand items and services each live on their own screen, so a
        // single total would send the reader to a list that filters most of it
        // out and looks empty.
        $categories = Category::with('parent')
            ->withCount([
                'products as stocked_count' => fn ($q) => $q->where('kind', Product::KIND_STOCK),
                'products as used_count' => fn ($q) => $q->where('kind', Product::KIND_USED),
                'products as service_count' => fn ($q) => $q->where('kind', Product::KIND_SERVICE),
            ])
            ->orderBy('name')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('categories.index', [
            'categories' => $categories,
            'parents' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Category::create($data);

        return back()->with('success', __('Category saved'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        // A category cannot be its own parent, nor a descendant's child.
        if ((int) ($data['parent_id'] ?? 0) === $category->id) {
            return back()->withErrors(['parent_id' => __('A category cannot be its own parent.')]);
        }

        $category->update($data);

        return back()->with('success', __('Category saved'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        // Section 8b: rows still referenced by a foreign key are refused with
        // the reason, rather than failing with a database error.
        if ($category->products()->exists()) {
            return back()->with('error', __('This category holds products. Move them first.'));
        }

        if ($category->children()->exists()) {
            return back()->with('error', __('This category has sub-categories. Remove them first.'));
        }

        $category->delete();

        return back()->with('success', __('Category deleted'));
    }

    /**
     * Section 9: bulk-assign selected products to a category. With one category
     * per product this is a bulk UPDATE of category_id, no pivot involved.
     */
    public function bulkAssign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $moved = DB::transaction(fn () => Product::whereIn('id', $data['product_ids'])
            ->update(['category_id' => $data['category_id']]));

        return back()->with('success', trans_choice(
            '{1} :count product moved|[2,*] :count products moved',
            $moved,
            ['count' => $moved],
        ));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:categories,id'],
        ]);
    }
}
