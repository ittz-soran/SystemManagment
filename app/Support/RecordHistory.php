<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
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

        // The record as it stands now, rolled back one entry at a time.
        $state = $model->getAttributes();

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

                $changes[] = [
                    'label' => __(self::LABELS[$field] ?? Str::headline($field)),
                    'from' => self::readable($field, $was),
                    'to' => self::readable($field, $state[$field] ?? null),
                ];

                $state[$field] = $was;
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

    /** A foreign key said as the name it points at, or as the number if it is gone. */
    private static function nameOf(string $model, mixed $id): string
    {
        return $model::withoutGlobalScopes()->find($id)?->name ?? '#'.$id;
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
    private static function readable(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
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
