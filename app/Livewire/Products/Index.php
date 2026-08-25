<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The product list, kept live.
 *
 * The screen used to be a form: type, press Filter, wait for a whole new page.
 * Every keystroke now narrows the table in place, and nothing else on the screen
 * is thrown away and rebuilt — the stylesheet, the sidebar and the scroll
 * position all stay where they are.
 *
 * Every filter is bound to the query string, so the address bar still describes
 * what is on screen: a filtered list can be bookmarked, sent to somebody else,
 * or reached from the categories page as it always could.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'categories', except: [])]
    public array $categories = [];

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'low_stock', except: false)]
    public bool $lowStock = false;

    #[Url(as: 'sort', except: 'name')]
    public string $sort = 'name';

    #[Url(as: 'dir', except: 'asc')]
    public string $direction = 'asc';

    /** @var list<int> */
    public array $selected = [];

    public bool $selectPage = false;

    /** Columns a reader may sort by, so a URL cannot ask for anything else. */
    private const SORTABLE = ['name', 'quantity', 'purchase_price', 'sale_price'];

    /** A narrower list is a different list; page 4 of it may not exist. */
    public function updated(string $field): void
    {
        if (in_array($field, ['search', 'status', 'lowStock'], true) || str_starts_with($field, 'categories')) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categories', 'status', 'lowStock']);
        $this->resetPage();
        $this->clearSelection();
    }

    /** Whether anything is narrowing the list, so the screen can say so. */
    #[Computed]
    public function filtered(): bool
    {
        return $this->search !== '' || $this->categories !== [] || $this->status !== '' || $this->lowStock;
    }

    public function updatedSelectPage(bool $value): void
    {
        // "All" means the page in front of the reader, not every row the filter
        // matches — a tick box cannot honestly claim 4,000 rows nobody has seen.
        $this->selected = $value
            ? $this->products->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    private function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    /**
     * Move every selected product to another category, in one go.
     *
     * The same action the old bulk form posted, minus the round trip: the table
     * simply shows the new category.
     */
    public function assignCategory(int $categoryId): void
    {
        $this->authorize('products.edit');

        if ($this->selected === []) {
            return;
        }

        $category = Category::findOrFail($categoryId);

        $moved = Product::whereIn('id', $this->selected)->update(['category_id' => $category->id]);

        $this->clearSelection();

        $this->dispatch('toast', message: trans_choice(
            '{1}:count product moved to :category|[2,*]:count products moved to :category',
            $moved,
            ['count' => number_format($moved), 'category' => $category->name],
        ));
    }

    /**
     * Section 5: a product with stock history is deactivated, never deleted —
     * its movements are somebody's invoice. The rule lives in the model layer
     * and is repeated here rather than trusted to the button.
     */
    public function delete(int $id): void
    {
        $this->authorize('products.delete');

        $product = Product::findOrFail($id);

        if ($product->stockMovements()->exists()) {
            $product->forceFill(['is_active' => false])->save();

            $this->dispatch('toast', message: __('This product has stock history, so it was deactivated instead of deleted.'));

            return;
        }

        $product->delete();

        $this->dispatch('toast', message: __('Product deleted'));
    }

    /**
     * The page on screen. Computed, so asking for it twice in one request —
     * once to tick every box, once to draw the table — is still one query.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, Product>
     */
    #[Computed]
    public function products()
    {
        return $this->query()->paginate(auth()->user()->items_per_page);
    }

    private function query(): Builder
    {
        return Product::query()
            // Second-hand items and services have their own screens.
            ->stocked()
            ->with('category')
            ->when($this->search !== '', fn (Builder $q) => $q->search(trim($this->search)))
            ->when($this->categories !== [], fn (Builder $q) => $q->whereIn('category_id', $this->categories))
            ->when($this->status !== '', fn (Builder $q) => $q->where('is_active', $this->status === 'active'))
            // Section 8c: a product with no reorder_level of its own falls back
            // to the shop's low_stock_threshold.
            ->when($this->lowStock, fn (Builder $q) => $q->whereRaw(
                'quantity <= COALESCE(reorder_level, ?)', [(int) setting('low_stock_threshold', 0)]
            ))
            ->orderBy(
                in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'name',
                $this->direction === 'desc' ? 'desc' : 'asc',
            );
    }

    public function render(): View
    {
        return view('livewire.products.index', [
            'products' => $this->products,
            'allCategories' => Category::whereIn('id', Product::stocked()->select('category_id'))
                ->orderBy('name')->get(),
            // Counted over the whole catalogue, with exactly the predicate the
            // tile's own filter uses — a figure that does not match what
            // clicking it shows is worse than no figure.
            'lowStockCount' => Product::stocked()->whereRaw(
                'quantity <= COALESCE(reorder_level, ?)', [(int) setting('low_stock_threshold', 0)]
            )->count(),
            'total' => Product::stocked()->count(),

            // What the shelves are worth at what was actually paid for the
            // stock still on them — the batches, not the suggested price.
            'stockValue' => (int) StockBatch::whereIn(
                'product_id', Product::stocked()->select('id')
            )->sum(DB::raw('quantity_remaining * unit_cost')),
        ])
            // The shell is the one the rest of the shop uses — sidebar, topbar,
            // toasts and the back link all keep working, and this screen fills
            // the same @section('content') the Blade page used to.
            ->extends('layouts.app')
            ->section('content');
    }
}
