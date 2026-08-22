<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Archive a period: write it to a file, then stop showing it.
 *
 * NOTHING IS DELETED, and that is the whole design.
 *
 * Deleting old documents would break the two things Section 4 and Section 5
 * build everything on. A purchase from eight months ago may still own a batch
 * with stock on the shelf — remove it and real units vanish from the system.
 * Every sale's movements name the batch they came from, which is the only
 * record of what those units cost; remove them and every profit figure before
 * the cut-off becomes unknowable. And a balance is a running total of the
 * ledger, so deleting old entries leaves a debt nobody can explain.
 *
 * So "archive" here means: export it, then set a date before which documents
 * are hidden from the day-to-day lists. Stock, costs, balances and reports all
 * keep working on the complete history, and a "show archived" toggle brings the
 * old documents back into view whenever anyone wants them.
 */
class PeriodArchiveService
{
    public const CUTOFF_KEY = 'archived_before';

    /**
     * Each export file, and the query behind it.
     *
     * Written as SQL rather than through the models so the file is a flat,
     * readable table — a spreadsheet an accountant opens, not an object graph.
     *
     * @return array<string, array{date: string, query: callable}>
     */
    private function sheets(): array
    {
        return [
            'sales' => ['date' => 'sales.sale_date', 'query' => fn () => DB::table('sales')
                ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->leftJoin('users', 'users.id', '=', 'sales.user_id')
                ->select('sales.document_no', 'sales.sale_date', 'customers.name as customer',
                    'sales.total_amount', 'sales.status', 'users.name as entered_by', 'sales.deleted_at')],

            'sale_items' => ['date' => 'sales.sale_date', 'query' => fn () => DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->select('sales.document_no', 'sales.sale_date', 'products.sku', 'products.name as product',
                    'sale_items.quantity', 'sale_items.unit_price', 'sale_items.quantity_returned')],

            'purchases' => ['date' => 'purchases.purchase_date', 'query' => fn () => DB::table('purchases')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                ->select('purchases.document_no', 'purchases.purchase_date', 'suppliers.name as supplier',
                    'purchases.supplier_invoice_no', 'purchases.total_amount', 'purchases.discount_amount',
                    'purchases.grand_total', 'purchases.status', 'purchases.deleted_at')],

            'purchase_items' => ['date' => 'purchases.purchase_date', 'query' => fn () => DB::table('purchase_items')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->leftJoin('products', 'products.id', '=', 'purchase_items.product_id')
                ->select('purchases.document_no', 'purchases.purchase_date', 'products.sku',
                    'products.name as product', 'purchase_items.quantity', 'purchase_items.unit_price')],

            'sale_returns' => ['date' => 'sale_returns.return_date', 'query' => fn () => DB::table('sale_returns')
                ->leftJoin('sales', 'sales.id', '=', 'sale_returns.sale_id')
                ->select('sale_returns.document_no', 'sale_returns.return_date',
                    'sales.document_no as against_sale', 'sale_returns.total_amount')],

            'purchase_returns' => ['date' => 'purchase_returns.return_date', 'query' => fn () => DB::table('purchase_returns')
                ->leftJoin('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
                ->select('purchase_returns.document_no', 'purchase_returns.return_date',
                    'purchases.document_no as against_purchase', 'purchase_returns.total_amount')],

            'payments' => ['date' => 'payments.paid_at', 'query' => fn () => DB::table('payments')
                ->select('payments.document_no', 'payments.paid_at', 'payments.payable_type',
                    'payments.amount', 'payments.direction', 'payments.payment_method', 'payments.notes')],

            'expenses' => ['date' => 'expenses.expense_date', 'query' => fn () => DB::table('expenses')
                ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->select('expenses.document_no', 'expenses.expense_date', 'expenses.title',
                    'expense_categories.name as category', 'expenses.amount', 'expenses.notes')],

            'stock_movements' => ['date' => 'stock_movements.occurred_at', 'query' => fn () => DB::table('stock_movements')
                ->leftJoin('products', 'products.id', '=', 'stock_movements.product_id')
                ->select('stock_movements.occurred_at', 'products.sku', 'products.name as product',
                    'stock_movements.reference_type', 'stock_movements.quantity', 'stock_movements.unit_cost',
                    'stock_movements.stock_batch_id')],

            'ledger' => ['date' => 'account_transactions.created_at', 'query' => fn () => DB::table('account_transactions')
                ->select('account_transactions.created_at', 'account_transactions.accountable_type',
                    'account_transactions.accountable_id', 'account_transactions.type',
                    'account_transactions.amount', 'account_transactions.balance_after',
                    'account_transactions.notes')],
        ];
    }

    /**
     * How much is in a period, sheet by sheet.
     *
     * @return array<string, int>
     */
    public function summary(?Carbon $from, Carbon $to): array
    {
        $counts = [];

        foreach ($this->sheets() as $name => $sheet) {
            $counts[$name] = (int) $this->scope($sheet, $from, $to)->count();
        }

        return $counts;
    }

    /**
     * Write the period to a ZIP of CSV files and return its path.
     *
     * Each file carries a UTF-8 byte-order mark, without which Excel on Windows
     * renders every Kurdish and Arabic name as mojibake.
     */
    public function export(?Carbon $from, Carbon $to): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('This server has no ZIP support, so a period cannot be exported. Enable the PHP zip extension.'));
        }

        $path = tempnam(sys_get_temp_dir(), 'period').'.zip';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('Could not write to :path', ['path' => $path]));
        }

        foreach ($this->sheets() as $name => $sheet) {
            $zip->addFromString($name.'.csv', $this->csv($this->scope($sheet, $from, $to)));
        }

        $zip->addFromString('README.txt', $this->readme($from, $to));

        $zip->close();

        return $path;
    }

    /**
     * Export the period, then hide it from the day-to-day lists.
     *
     * @return array{path: string, counts: array<string, int>}
     */
    public function archive(Carbon $through, User $user, bool $freeze = true): array
    {
        $counts = $this->summary(null, $through);
        $path = $this->export(null, $through);

        // The day AFTER the last archived one: everything strictly before this
        // date is hidden, which is the same shape as books_closed_before.
        $cutoff = $through->copy()->addDay()->toDateString();

        Setting::put(self::CUTOFF_KEY, $cutoff);

        // Archiving a period almost always means it is finished. Freezing it as
        // well is what stops someone editing a document that is now only
        // described by a file on a drive.
        if ($freeze) {
            Setting::put('books_closed_before', $cutoff);
        }

        Setting::flushCache();

        app(ActivityLogger::class)->log(
            action: 'update',
            module: 'settings',
            description: __('Archived everything up to :date (:count documents). Nothing was deleted.', [
                'date' => $through->toDateString(),
                'count' => array_sum($counts),
            ]),
            user: $user,
        );

        return ['path' => $path, 'counts' => $counts];
    }

    /** Bring the archived period back into view. Nothing was lost, so nothing is restored. */
    public function unhide(User $user): void
    {
        Setting::put(self::CUTOFF_KEY, null);
        Setting::flushCache();

        app(ActivityLogger::class)->log(
            action: 'update',
            module: 'settings',
            description: __('Showed the archived period again'),
            user: $user,
        );
    }

    public function cutoff(): ?Carbon
    {
        $value = setting(self::CUTOFF_KEY);

        return filled($value) ? Carbon::parse($value)->startOfDay() : null;
    }

    /**
     * @param  array{date: string, query: callable}  $sheet
     */
    private function scope(array $sheet, ?Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        $query = ($sheet['query'])()->whereDate($sheet['date'], '<=', $to);

        return $from === null ? $query : $query->whereDate($sheet['date'], '>=', $from);
    }

    private function csv(\Illuminate\Database\Query\Builder $query): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\u{FEFF}");

        $first = true;

        foreach ($query->cursor() as $row) {
            $row = (array) $row;

            if ($first) {
                fputcsv($handle, array_keys($row));
                $first = false;
            }

            fputcsv($handle, array_map(fn ($value) => $value ?? '', $row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function readme(?Carbon $from, Carbon $to): string
    {
        return implode("\n", [
            setting('shop_name', config('app.name')),
            '',
            __('Period: :from to :to', [
                'from' => $from?->toDateString() ?? __('the beginning'),
                'to' => $to->toDateString(),
            ]),
            __('Exported: :when', ['when' => now()->toDateTimeString()]),
            '',
            __('This is a copy, not a removal. Everything in these files is still in the system.'),
            __('Stock levels, costs and balances are all worked out from the complete history, so nothing here can be deleted without breaking them.'),
            '',
        ]);
    }
}
