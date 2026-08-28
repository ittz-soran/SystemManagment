<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Category;
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
    ];

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

        if ($field === 'category_id') {
            return Category::find($value)?->name ?? __('Category #:id', ['id' => $value]);
        }

        if (str_starts_with($field, 'is_')) {
            return $value ? __('Yes') : __('No');
        }

        if (str_ends_with($field, '_price') || str_ends_with($field, '_cost') || $field === 'amount') {
            return money((int) $value, false);
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return (string) $value;
    }
}
