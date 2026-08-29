<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'name', 'kind', 'sku', 'barcode', 'category_id', 'unit', 'condition_note',
    'acquired_from_id', 'purchase_price', 'sale_price', 'quantity',
    'reorder_level', 'is_active',
])]
class Product extends Model
{
    use SoftDeletes;

    /** Ordinary stock: many interchangeable units, costed FIFO. */
    public const KIND_STOCK = 'stock';

    /**
     * One physical second-hand thing. Its row holds one unit and one batch, so
     * the cost FIFO records against its sale is the price actually paid for it —
     * which is the only cost it has.
     */
    public const KIND_USED = 'used';

    /** Sold, never stocked: no batch, no movement, no cost. */
    public const KIND_SERVICE = 'service';

    protected function casts(): array
    {
        return [
            'purchase_price' => 'integer',
            'sale_price' => 'integer',
            'quantity' => 'integer',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Second-hand only: the person the shop bought this one item from. */
    public function acquiredFrom(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'acquired_from_id');
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** The lines this product has been sold on. */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Whether this row has stock at all.
     *
     * The one question the sale, return and adjustment paths ask before they go
     * near the FIFO engine: a service has nothing to consume and nothing to put
     * back, so calling the engine for one would either write a movement for
     * goods that never existed or fail looking for a batch that never will.
     */
    public function tracksStock(): bool
    {
        return $this->kind !== self::KIND_SERVICE;
    }

    public function isService(): bool
    {
        return $this->kind === self::KIND_SERVICE;
    }

    public function isUsed(): bool
    {
        return $this->kind === self::KIND_USED;
    }

    /** A second-hand item is sold once; after that its row is history. */
    public function isSold(): bool
    {
        return $this->isUsed() && $this->quantity === 0;
    }

    /**
     * Section 4: the real stock. `products.quantity` is only a cache of this.
     * If the two ever differ, the batches win.
     */
    public function trueStock(): int
    {
        return (int) $this->stockBatches()->sum('quantity_remaining');
    }

    /**
     * Section 8c: a product with no reorder_level falls back to the global
     * low_stock_threshold setting.
     */
    public function effectiveReorderLevel(): int
    {
        return $this->reorder_level ?? (int) setting('low_stock_threshold', 0);
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->effectiveReorderLevel();
    }

    /** @param  string|array<int, string>  $kind */
    public function scopeOfKind(Builder $query, string|array $kind): Builder
    {
        return $query->whereIn('kind', (array) $kind);
    }

    /**
     * The ordinary catalogue. Second-hand items and services are real products
     * and sell like them, but they do not belong in a list of what the shop
     * stocks — one is a single thing that will never be reordered, the other is
     * not a thing at all.
     */
    /**
     * Everything that can hold a product down, and what to call it out loud.
     *
     * These are the seven `restrict` foreign keys in Section 5's table — the
     * ones that make "a product with stock history must never vanish" a rule
     * the database keeps rather than a rule the code remembers to.
     *
     * Where a document owns the row, the document is what gets counted: a
     * shopkeeper knows what "2 sales" means and has never heard of a sale item.
     *
     * @var array<string, array{0: string|null, 1: string}>
     */
    private const HELD_BY = [
        'sale_items' => ['sale_id', 'sales'],
        'purchase_items' => ['purchase_id', 'purchases'],
        'sale_return_items' => ['sale_return_id', 'sale returns'],
        'purchase_return_items' => ['purchase_return_id', 'purchase returns'],
        'stock_adjustments' => [null, 'stock adjustments'],
        'stock_batches' => [null, 'stock batches'],
        'stock_movements' => [null, 'stock movements'],
    ];

    /**
     * What still points at this product, counted the way the database counts.
     *
     * Asked with the query builder and not through relations on purpose. A
     * foreign key sees every row in the table — it does not know about soft
     * deletes, and it has never heard of the archived-period scope that hides
     * old stock adjustments from the screens. Counting through Eloquent would
     * answer a different question from the one MySQL is about to ask, and the
     * two disagreeing is exactly how a friendly "you can delete this" turns
     * into an integrity-constraint error page.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>> product id => label => how many
     */
    public static function purgeBlockers(array $ids): array
    {
        $found = array_fill_keys($ids, []);

        foreach (self::HELD_BY as $table => [$parent, $label]) {
            $rows = DB::table($table)
                ->select('product_id')
                ->selectRaw($parent
                    ? 'COUNT(DISTINCT '.$parent.') as total'
                    : 'COUNT(*) as total')
                ->whereIn('product_id', $ids)
                ->groupBy('product_id')
                ->get();

            foreach ($rows as $row) {
                if ($row->total > 0) {
                    $found[$row->product_id][$label] = (int) $row->total;
                }
            }
        }

        return $found;
    }

    /**
     * Whether this product can be destroyed outright, and why not when it cannot.
     *
     * Section 8's shape, and the same promise: the reason is a sentence the
     * shopkeeper can act on, worked out before the button rather than handed
     * back afterwards as a crash.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canBePurged(): array
    {
        $reason = self::describeBlockers(self::purgeBlockers([$this->getKey()])[$this->getKey()] ?? []);

        return ['allowed' => $reason === null, 'reason' => $reason];
    }

    /**
     * What is holding a product, as a sentence — or null when nothing is.
     *
     * Separate from canBePurged() so the deleted list can say it against every
     * row from one set of counts, rather than asking the same seven questions
     * again for each line on the page.
     *
     * @param  array<string, int>  $held
     */
    public static function describeBlockers(array $held): ?string
    {
        if ($held === []) {
            return null;
        }

        $said = [];

        /*
         * Written out one literal at a time rather than as a match returning a
         * key, because translations:check reads __() and trans_choice() by
         * tokenising the source: a key arrived at through a variable is a key
         * it cannot see, and a string it cannot see ships in English while the
         * command reports 100%.
         */
        foreach ($held as $label => $count) {
            $n = ['count' => number_format($count)];

            $said[] = match ($label) {
                'sales' => trans_choice('{1}:count sale|[2,*]:count sales', $count, $n),
                'purchases' => trans_choice('{1}:count purchase|[2,*]:count purchases', $count, $n),
                'sale returns' => trans_choice('{1}:count sale return|[2,*]:count sale returns', $count, $n),
                'purchase returns' => trans_choice('{1}:count purchase return|[2,*]:count purchase returns', $count, $n),
                'stock adjustments' => trans_choice('{1}:count stock adjustment|[2,*]:count stock adjustments', $count, $n),
                'stock batches' => trans_choice('{1}:count stock batch|[2,*]:count stock batches', $count, $n),
                default => trans_choice('{1}:count stock movement|[2,*]:count stock movements', $count, $n),
            };
        }

        return __('This product is on :things, so it cannot be destroyed. Bring it back or leave it deleted.', [
            'things' => Arr::join($said, ', ', ' '.__('and').' '),
        ]);
    }

    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_STOCK);
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_USED);
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SERVICE);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Section 9b: scanning searches barcode first, then sku, then name.
     * The caller decides what to do with an exact barcode hit versus a name match.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('barcode', $term)
                ->orWhere('sku', $term)
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }
}
