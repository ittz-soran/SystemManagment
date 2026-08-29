<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What has happened to one record, in the order it happened.
 *
 * activity_logs already holds it — every create, edit and delete, with who and
 * when, and the previous version of whatever changed. What it does not hold is
 * the *new* value: the observer stores only what a field was before. So an edit
 * on its own can say "sale price was 15,000" and not what it became.
 *
 * Which is recoverable, and this is where. Read the entries newest first,
 * starting from the record as it stands now: whatever the state map holds for a
 * field is what that field became at that entry, and the entry's own old_values
 * say what it was before. Roll the map back and move to the older entry. Both
 * sides of every change, exactly, with no extra column and no second table.
 */
final class RecordHistory
{
    /** Fields the shop has its own words for. Anything else is title-cased. */
    private const LABELS = [
        'name' => 'Name',
        'sku' => 'SKU',
        'barcode' => 'Barcode',
        'category_id' => 'Category',
        'unit' => 'Unit',
        'purchase_price' => 'Purchase price',
        'sale_price' => 'Sale price',
        'reorder_level' => 'Reorder level',
        'is_active' => 'Active',
        'condition' => 'Condition',
        'notes' => 'Notes',
        'phone' => 'Phone',
        'address' => 'Address',
        'quantity' => 'Quantity',
        'unit_cost' => 'Cost each',
        'direction' => 'Direction',
        'reason' => 'Reason',

        // The documents.
        'document_no' => 'Document number',
        'customer_id' => 'Customer',
        'supplier_id' => 'Supplier',
        'expense_category_id' => 'Category',
        'lines' => 'Items',
        'supplier_invoice_no' => "Supplier's invoice number",
        'exchange_rate' => 'Exchange rate',
        'total_amount' => 'Total',
        'grand_total' => 'Grand total',
        'discount_amount' => 'Discount',
        'amount' => 'Amount',
        'amount_paid' => 'Paid',
        'sale_date' => 'Date',
        'purchase_date' => 'Date',
        'expense_date' => 'Date',
        'adjusted_at' => 'Date',
        'paid_at' => 'Date',
        'returned_at' => 'Date',
        'status' => 'Status',
        'method' => 'Method',
        'reference' => 'Reference',
        'payable_type' => 'Against',
        'payable_id' => 'Against',
        'is_system' => 'Built in',
        'balance' => 'Balance',
        'kind' => 'Kind',
        'condition_note' => 'Condition',
        'acquired_from_id' => 'Bought from',
        'reorder_level' => 'Reorder level',
        'password' => 'Password',
        'role' => 'Role',
        'is_active' => 'Active',
        'cost_visibility' => 'What they see a thing cost',
        'cost_markup_percent' => 'Percentage added',
        'language' => 'Language',
        'theme' => 'Theme',
        'items_per_page' => 'Rows per page',
        'email' => 'Email',
    ];

    /**
     * Never shown, whatever the log holds.
     *
     * The observer stores the previous value of everything that changed, and a
     * changed password means the old hash is sitting in old_values. It is not
     * much use to anybody, but a password hash does not belong on a screen —
     * that a password changed, and who changed it, is the whole of what a
     * history is for here.
     */
    private const SECRET = ['password', 'remember_token'];

    /**
     * Not worth a line.
     *
     * The observer stores the previous value of everything that changed, and
     * `updated_at` changes on every save by definition — so every entry in
     * every history carried "Updated At: 28 Aug → 28 Aug", which is the same
     * date twice and is already the timestamp beside the entry.
     */
    private const NOT_WORTH_SAYING = ['updated_at', 'created_at'];

    /**
     * @return list<array{
     *     action: string,
     *     at: Carbon,
     *     by: string,
     *     ip: string|null,
     *     description: string|null,
     *     changes: list<array{label: string, from: string, to: string}>
     * }>
     */
    public static function for(Model $model, int $limit = 50): array
    {
        $entries = ActivityLog::with('user')
            ->where('module', self::moduleFor($model))
            ->where('record_id', $model->getKey())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Names are looked up as they are needed and kept for this read only;
        // holding them longer would outlive a rename.
        self::$names = [];

        // The record as it stands now, rolled back one entry at a time.
        $state = $model->getAttributes();

        // Except the cart, which is not a column. A sale or a purchase edit
        // stores the whole previous set of lines under `lines` — Section 8's
        // "the full previous version in old_values" — so the map has to start
        // with the lines as they stand, or the newest edit has no way to say
        // what the cart became.
        if ($model instanceof Sale || $model instanceof Purchase) {
            $state['lines'] = self::linesOf($model);
        }

        $history = [];

        foreach ($entries as $entry) {
            $changes = [];

            foreach (($entry->old_values ?? []) as $field => $was) {
                if (in_array($field, self::NOT_WORTH_SAYING, true)) {
                    $state[$field] = $was;

                    continue;
                }

                if (in_array($field, self::SECRET, true)) {
                    $changes[] = [
                        'label' => __(self::LABELS[$field] ?? Str::headline($field)),
                        'from' => '•••',
                        'to' => __('changed'),
                    ];

                    continue;
                }

                $from = self::readable($model, $field, $was);
                $to = self::readable($model, $field, $state[$field] ?? null);

                $state[$field] = $was;

                // The observer stores only what changed, but the sale and
                // purchase services snapshot the whole document — so an edit
                // that touched nothing but the cart still read "Document
                // number: INV-00010 → INV-00010" on four more lines. Nothing
                // moved is nothing worth saying.
                if ($from === $to) {
                    continue;
                }

                $changes[] = [
                    'label' => __(self::LABELS[$field] ?? Str::headline($field)),
                    'from' => $from,
                    'to' => $to,
                ];
            }

            $history[] = [
                'action' => $entry->action,
                'at' => $entry->created_at,
                'by' => $entry->user?->name ?? __('Somebody no longer on the staff'),
                'ip' => $entry->ip_address,
                'description' => $entry->description,
                'changes' => $changes,
            ];
        }

        return $history;
    }

    /**
     * A foreign key said as the name it points at, or as the number if it is gone.
     *
     * Kept for the length of the read: a cart names the same product on every
     * entry it appears in, and fifty entries of a five-line invoice would
     * otherwise be two hundred and fifty queries to write out five names.
     *
     * @var array<string, string>
     */
    private static array $names = [];

    private static function nameOf(string $model, mixed $id): string
    {
        if ($id === null || $id === '') {
            return '—';
        }

        return self::$names[$model.'#'.$id] ??= $model::withoutGlobalScopes()->find($id)?->name ?? '#'.$id;
    }

    /**
     * The cart as it stands, in the shape the services snapshot it in.
     *
     * @return list<array{product_id: int|null, quantity: int, unit_price: int}>
     */
    private static function linesOf(Sale|Purchase $document): array
    {
        return $document->items()->get()
            ->map(fn ($line) => [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
            ])
            ->all();
    }

    /**
     * A cart said the way the printed invoice says it: 3 × Type-C cable @ 5,000.
     *
     * @param  array<mixed>  $lines
     */
    private static function cart(Model $document, array $lines): string
    {
        $lines = array_values(array_filter($lines, 'is_array'));

        if ($lines === []) {
            return '—';
        }

        // What a purchase line cost is a cost, and not every reader may see
        // one; what a sale line sold for is the customer's own price, which
        // everybody who can open the invoice may see.
        $isCost = $document instanceof Purchase;

        return implode(', ', array_map(function (array $line) use ($isCost): string {
            $price = (int) ($line['unit_price'] ?? 0);

            return number_format((int) ($line['quantity'] ?? 0))
                .' × '.self::nameOf(Product::class, $line['product_id'] ?? null)
                .' @ '.($isCost ? cost_money($price, false) : money($price, false));
        }, $lines));
    }

    /** Same slug the logger writes: StockAdjustment becomes stock_adjustments. */
    private static function moduleFor(Model $model): string
    {
        return Str::snake(Str::pluralStudly(class_basename($model)));
    }

    /**
     * A stored column as the shopkeeper would say it.
     *
     * By the shape of the name rather than by a per-model map: every price in
     * this system is an integer of dinars, every is_* is a yes or a no, and
     * category_id is the only foreign key that ever reaches a screen like this.
     */
    private static function readable(Model $record, string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if ($field === 'lines' && is_array($value)) {
            return self::cart($record, $value);
        }

        // Everything below this expects one figure, and the cast at the end of
        // this method expected one too. A cart arrived instead — "Array to
        // string conversion" — and took every edited invoice in the shop off
        // the screen at once. Whatever it is, it leaves here printable.
        if (! is_scalar($value)) {
            return match (true) {
                $value instanceof \DateTimeInterface => Carbon::instance($value)->format(setting('date_format', 'Y-m-d')),
                $value instanceof \BackedEnum => (string) $value->value,
                $value instanceof \UnitEnum => $value->name,
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—',
                default => '—',
            };
        }

        if ($field === 'category_id' || $field === 'expense_category_id') {
            return self::nameOf($field === 'category_id' ? Category::class : ExpenseCategory::class, $value);
        }

        if ($field === 'customer_id') {
            return self::nameOf(Customer::class, $value);
        }

        if ($field === 'supplier_id' || $field === 'acquired_from_id') {
            return self::nameOf(Supplier::class, $value);
        }

        if (str_ends_with($field, '_at') || str_ends_with($field, '_date')) {
            return Carbon::parse($value)->format(setting('date_format', 'Y-m-d'));
        }

        if (str_starts_with($field, 'is_')) {
            return $value ? __('Yes') : __('No');
        }

        // Every money column in the system is an integer of dinars.
        if (in_array($field, [
            'amount', 'amount_paid', 'balance', 'total_amount', 'grand_total', 'discount_amount',
        ], true) || str_ends_with($field, '_price') || str_ends_with($field, '_cost')) {
            return money((int) $value, false);
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return (string) $value;
    }
}
