<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentCounter;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Does the shop's data still agree with itself?
 *
 * Section 10b ends with six assertions "to run globally, after every test". They
 * are in the acceptance test, where they guard the engine against the developer.
 * This is the same idea pointed the other way: at real data, on a real evening,
 * after a year of a real shop — where the risk is not a bug in the FIFO code but
 * a power cut halfway through a sale, a restored backup from the wrong hour, or
 * a well-meant edit made straight in phpMyAdmin.
 *
 * The distinction that makes it worth reading:
 *
 *   A CACHE may be rebuilt. products.quantity, a customer's balance, a document
 *   status — every one of them is derived from something else, so if it drifts,
 *   the truth is still there and the cache can be recomputed. Annoying, fixable.
 *
 *   A TRUTH may not. A batch, a movement, a ledger row — nothing else can
 *   reproduce these, so a contradiction between two of them means one is wrong
 *   and the system cannot say which. That is the kind worth a shopkeeper's
 *   evening.
 *
 * So every check says which kind it is, and the page sorts by it. And every
 * check is written as one set-based query rather than a loop over the table: a
 * shop with five years of trading has roughly two hundred thousand movements,
 * and a check that takes ten minutes is a check nobody runs.
 */
final class DataIntegrityService
{
    /** Nothing to say. */
    public const OK = 'ok';

    /** A cache has drifted. The truth is intact and this can be rebuilt. */
    public const REBUILDABLE = 'rebuildable';

    /** Two records that cannot both be right. Needs a person. */
    public const SERIOUS = 'serious';

    /** How many offending rows to name. The count is always exact. */
    private const EXAMPLES = 8;

    /**
     * @return array{
     *     checks: list<array<string, mixed>>,
     *     serious: int, rebuildable: int, ok: int,
     *     rows: int, ran_for: float
     * }
     */
    public function run(): array
    {
        $startedAt = microtime(true);

        $checks = [
            // ---- Stock: the shelf ------------------------------------------
            $this->stockCacheMatchesBatches(),
            $this->stockCacheMatchesMovements(),
            $this->batchesWithinTheirBounds(),
            $this->batchesMatchTheirMovements(),
            $this->saleMovementsNameTheirLine(),
            $this->servicesHoldNoStock(),

            // ---- Money: the books ------------------------------------------
            $this->balancesMatchTheLedger(),
            $this->theLedgerAddsUp(),
            $this->documentTotalsMatchTheirLines(),
            $this->returnTotalsMatchTheirLines(),
            $this->nothingIsOverpaid(),
            $this->theCashCustomerOwesNothing(),

            // ---- Documents: the paperwork ----------------------------------
            $this->noDocumentNumberIsUsedTwice(),
            $this->countersAreAheadOfWhatIsUsed(),
            $this->nothingIsReturnedMoreThanOnce(),
            $this->statusesMatchTheirLines(),

            // ---- Links: the joins nothing enforces --------------------------
            $this->everyLinkPointsAtSomething(),
        ];

        $rows = 0;

        foreach ($checks as $check) {
            $rows += $check['examined'];
        }

        return [
            'checks' => $checks,
            'serious' => count(array_filter($checks, fn ($c) => $c['severity'] === self::SERIOUS)),
            'rebuildable' => count(array_filter($checks, fn ($c) => $c['severity'] === self::REBUILDABLE)),
            'ok' => count(array_filter($checks, fn ($c) => $c['severity'] === self::OK)),
            'rows' => $rows,
            'ran_for' => round(microtime(true) - $startedAt, 2),
        ];
    }

    // =================================================================
    // Stock
    // =================================================================

    /**
     * Section 4: "products.quantity is a cache, not the truth."
     *
     * The truth is the batches. If these disagree, the shelf figure on every
     * screen is wrong and the fix is to add the batches up again — which is
     * exactly what Recheck stock does.
     */
    private function stockCacheMatchesBatches(): array
    {
        $rows = DB::table('products as p')
            ->leftJoinSub(
                DB::table('stock_batches')->select('product_id')
                    ->selectRaw('SUM(quantity_remaining) as total')->groupBy('product_id'),
                'b',
                'b.product_id',
                '=',
                'p.id',
            )
            ->whereRaw('p.quantity <> COALESCE(b.total, 0)')
            ->select('p.id', 'p.name', 'p.sku', 'p.quantity')
            ->selectRaw('COALESCE(b.total, 0) as batches')
            ->orderBy('p.name')
            ->get();

        return $this->result(
            key: 'stock_cache',
            group: __('Stock'),
            title: __('The stock figure matches the batches behind it'),
            because: __('products.quantity is a cache of the batches, so a difference means every screen is showing the wrong number for that product — not that stock has actually been lost.'),
            examined: (int) DB::table('products')->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $r->name.' · '.$r->sku,
                'says' => __('screen says :cached, batches hold :real', [
                    'cached' => number_format((int) $r->quantity),
                    'real' => number_format((int) $r->batches),
                ]),
                'url' => route('products.show', $r->id),
            ])->all(),
            kind: self::REBUILDABLE,
            repair: 'stock.recheck',
        );
    }

    /**
     * Section 10b assertion 2, the second half — and a different question.
     *
     * The batches say what is left; the movements say everything that ever
     * happened. They are written by different code paths, so agreeing is real
     * evidence rather than one number copied twice.
     */
    private function stockCacheMatchesMovements(): array
    {
        $rows = DB::table('products as p')
            ->leftJoinSub(
                DB::table('stock_movements')->select('product_id')
                    ->selectRaw('SUM(quantity) as total')->groupBy('product_id'),
                'm',
                'm.product_id',
                '=',
                'p.id',
            )
            ->whereRaw('p.quantity <> COALESCE(m.total, 0)')
            ->select('p.id', 'p.name', 'p.sku', 'p.quantity')
            ->selectRaw('COALESCE(m.total, 0) as movements')
            ->orderBy('p.name')
            ->get();

        return $this->result(
            key: 'movement_sum',
            group: __('Stock'),
            title: __('Every movement in and out adds up to what is on the shelf'),
            because: __('The batches say what is left and the movements say everything that ever happened. They are written separately, so when they agree it is evidence rather than the same number stored twice.'),
            examined: (int) DB::table('stock_movements')->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $r->name.' · '.$r->sku,
                'says' => __('shelf says :cached, the movements add to :real', [
                    'cached' => number_format((int) $r->quantity),
                    'real' => number_format((int) $r->movements),
                ]),
                'url' => route('products.show', $r->id),
            ])->all(),
            kind: self::SERIOUS,
        );
    }

    /** Section 10b assertion 1: a batch may never hold more than it was bought with. */
    private function batchesWithinTheirBounds(): array
    {
        $rows = DB::table('stock_batches as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->whereColumn('b.quantity_remaining', '>', 'b.quantity_in')
            ->select('b.id', 'b.product_id', 'b.quantity_in', 'b.quantity_remaining', 'p.name')
            ->get();

        return $this->result(
            key: 'batch_bounds',
            group: __('Stock'),
            title: __('No batch holds more than was bought into it'),
            because: __('A batch that grew past what was bought means units exist that nobody paid for, and FIFO would sell them at a cost that was never real.'),
            examined: (int) DB::table('stock_batches')->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $r->name.' · '.__('batch #:id', ['id' => $r->id]),
                'says' => __(':remaining left out of :in bought', [
                    'remaining' => number_format((int) $r->quantity_remaining),
                    'in' => number_format((int) $r->quantity_in),
                ]),
                'url' => route('products.show', $r->product_id),
            ])->all(),
            kind: self::SERIOUS,
        );
    }

    /**
     * The deep one, and the reason this page is worth having.
     *
     * Every batch carries its own arithmetic: the purchase that filled it wrote
     * a movement in, every sale that drew on it wrote one out, and every return
     * that refilled it wrote one back. Add that column up and it must land on
     * what the batch says is left — for every batch, not in total, because two
     * batches wrong in opposite directions cancel in a total and the shop
     * carries on selling at the wrong cost.
     */
    private function batchesMatchTheirMovements(): array
    {
        $rows = DB::table('stock_batches as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoinSub(
                DB::table('stock_movements')->select('stock_batch_id')
                    ->selectRaw('SUM(quantity) as total')->groupBy('stock_batch_id'),
                'm',
                'm.stock_batch_id',
                '=',
                'b.id',
            )
            ->whereRaw('b.quantity_remaining <> COALESCE(m.total, 0)')
            ->select('b.id', 'b.product_id', 'b.quantity_remaining', 'p.name')
            ->selectRaw('COALESCE(m.total, 0) as movements')
            ->get();

        return $this->result(
            key: 'batch_ledger',
            group: __('Stock'),
            title: __('Each batch agrees with its own movements, one by one'),
            because: __('Checked per batch rather than in total, because two batches wrong in opposite directions cancel out in a total while every sale from either draws the wrong cost.'),
            examined: (int) DB::table('stock_batches')->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $r->name.' · '.__('batch #:id', ['id' => $r->id]),
                'says' => __('batch says :remaining, its movements say :real', [
                    'remaining' => number_format((int) $r->quantity_remaining),
                    'real' => number_format((int) $r->movements),
                ]),
                'url' => route('products.show', $r->product_id),
            ])->all(),
            kind: self::SERIOUS,
        );
    }

    /** Section 10b assertion 5. Without the line, a partial return cannot find its units. */
    private function saleMovementsNameTheirLine(): array
    {
        $rows = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->whereIn('m.reference_type', ['sale', 'sale_return', 'purchase_return'])
            ->whereNull('m.reference_item_id')
            ->select('m.id', 'm.reference_type', 'm.reference_id', 'm.product_id', 'p.name')
            ->get();

        return $this->result(
            key: 'movement_line',
            group: __('Stock'),
            title: __('Every sale and return movement names the line it belongs to'),
            because: __('A second partial return has to know exactly which units came back the first time. Without the line it cannot, and the return picks up in the wrong place.'),
            examined: (int) DB::table('stock_movements')
                ->whereIn('reference_type', ['sale', 'sale_return', 'purchase_return'])->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $r->name,
                'says' => __('movement #:id on :type #:ref has no line', [
                    'id' => $r->id, 'type' => $this->say($r->reference_type), 'ref' => $r->reference_id,
                ]),
                'url' => route('products.show', $r->product_id),
            ])->all(),
            kind: self::SERIOUS,
        );
    }

    /** Section 4: a service is sold, not stocked. It has nothing to consume. */
    private function servicesHoldNoStock(): array
    {
        $rows = DB::table('products as p')
            ->where('p.kind', 'service')
            ->where(function ($q) {
                $q->where('p.quantity', '<>', 0)
                    ->orWhereExists(fn ($e) => $e->from('stock_batches')->whereColumn('product_id', 'p.id'))
                    ->orWhereExists(fn ($e) => $e->from('stock_movements')->whereColumn('product_id', 'p.id'));
            })
            ->select('p.id', 'p.name', 'p.sku', 'p.quantity')
            ->get();

        return $this->result(
            key: 'service_stock',
            group: __('Stock'),
            title: __('No service is carrying stock'),
            because: __('A service has nothing bought for it and nothing consumed, so a batch or a movement against one is a repair being costed as if it were a thing on a shelf.'),
            examined: (int) DB::table('products')->where('kind', 'service')->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $r->name.' · '.$r->sku,
                'says' => __('a service holding :qty', ['qty' => number_format((int) $r->quantity)]),
                'url' => route('services.index'),
            ])->all(),
            kind: self::SERIOUS,
        );
    }

    // =================================================================
    // Money
    // =================================================================

    /** Section 10b assertion 4. The ledger is the truth; the balance is a copy of its last line. */
    private function balancesMatchTheLedger(): array
    {
        $failures = [];
        $examined = 0;

        foreach ([['customer', 'customers'], ['supplier', 'suppliers']] as [$type, $table]) {
            $examined += (int) DB::table($table)->count();

            $rows = DB::table($table.' as a')
                ->leftJoin('account_transactions as t', function ($join) use ($type) {
                    $join->on('t.id', '=', DB::raw(
                        '(select max(id) from account_transactions '.
                        "where accountable_type = '{$type}' and accountable_id = a.id)"
                    ));
                })
                ->whereRaw('a.balance <> COALESCE(t.balance_after, 0)')
                ->select('a.id', 'a.name', 'a.balance')
                ->selectRaw('COALESCE(t.balance_after, 0) as ledger')
                ->get();

            foreach ($rows as $row) {
                $failures[] = [
                    'what' => $row->name,
                    'says' => __('shown as :shown, the ledger ends at :real', [
                        'shown' => money((int) $row->balance),
                        'real' => money((int) $row->ledger),
                    ]),
                    'url' => $type === 'customer'
                        ? route('customers.show', $row->id)
                        : route('suppliers.show', $row->id),
                ];
            }
        }

        return $this->result(
            key: 'balance_cache',
            group: __('Money'),
            title: __('Every balance matches the last line of its ledger'),
            because: __('A balance is a copy of where the account transactions ended. The ledger is the truth, so a difference means the figure on the screen is wrong, not that money moved.'),
            examined: $examined,
            failures: $failures,
            kind: self::REBUILDABLE,
        );
    }

    /**
     * Stronger than assertion 4, and this is the one that would catch a bad
     * restore.
     *
     * Assertion 4 asks whether the balance matches the ledger's *last* line. It
     * would pass on a ledger with a row missing from the middle, because the
     * cache was written from the same broken chain. So every line is checked
     * against the one before it: the running total from zero has to land on
     * each balance_after in turn.
     *
     * That works because LedgerService records what was actually applied rather
     * than what was asked for — a debt that would have gone negative is floored
     * at zero and the row stores the floored amount. So the chain is exact, with
     * no special cases.
     */
    private function theLedgerAddsUp(): array
    {
        $rows = DB::table(DB::raw(
            '(select id, accountable_type, accountable_id, amount, balance_after, '.
            'sum(amount) over (partition by accountable_type, accountable_id order by id '.
            'rows between unbounded preceding and current row) as running '.
            'from account_transactions) as t'
        ))
            ->whereRaw('t.running <> t.balance_after')
            ->orderBy('t.id')
            ->limit(self::EXAMPLES + 1)
            ->get();

        // The window query cannot also be counted cheaply, so the total is asked
        // for separately and only when something is actually wrong.
        $failed = $rows->isEmpty() ? 0 : (int) DB::table(DB::raw(
            '(select balance_after, '.
            'sum(amount) over (partition by accountable_type, accountable_id order by id '.
            'rows between unbounded preceding and current row) as running '.
            'from account_transactions) as t'
        ))->whereRaw('t.running <> t.balance_after')->count();

        // Named rather than numbered. Only the handful being shown are looked
        // up, so a broken ledger with ten thousand entries still costs two
        // queries — but the shopkeeper reads "Karwan Ahmed", not "customer #2".
        $names = ['customer' => [], 'supplier' => []];

        foreach (['customer' => Customer::class, 'supplier' => Supplier::class] as $type => $model) {
            $ids = $rows->where('accountable_type', $type)->pluck('accountable_id')->unique();

            if ($ids->isNotEmpty()) {
                $names[$type] = $model::withoutGlobalScopes()->whereKey($ids)->pluck('name', 'id')->all();
            }
        }

        return $this->result(
            key: 'ledger_chain',
            group: __('Money'),
            title: __('The ledger runs straight from the first entry to the last'),
            because: __('Every entry has to land on the running total of the ones before it. A balance that only matches the final line would still look right with an entry missing from the middle — this is the check that would not.'),
            examined: (int) DB::table('account_transactions')->count(),
            failures: $rows->map(fn ($r) => [
                'what' => $names[$r->accountable_type][$r->accountable_id]
                    ?? $this->say($r->accountable_type).' #'.$r->accountable_id,
                'says' => __('entry #:entry stores :stored, the entries before it add to :real', [
                    'entry' => $r->id,
                    'stored' => money((int) $r->balance_after),
                    'real' => money((int) $r->running),
                ]),
                'url' => $r->accountable_type === 'customer'
                    ? route('customers.show', $r->accountable_id)
                    : route('suppliers.show', $r->accountable_id),
            ])->all(),
            kind: self::SERIOUS,
            failedOverride: $failed,
        );
    }

    /** A document's total is the sum of its lines. Nothing else may set it. */
    private function documentTotalsMatchTheirLines(): array
    {
        $failures = [];

        foreach ([
            ['sales', 'sale_items', 'sale_id', 'total_amount', 'sales.show'],
            ['purchases', 'purchase_items', 'purchase_id', 'total_amount', 'purchases.show'],
        ] as [$table, $items, $key, $column, $route]) {
            $rows = DB::table($table.' as d')
                ->leftJoinSub(
                    DB::table($items)->select($key)
                        ->selectRaw('SUM(quantity * unit_price) as total')->groupBy($key),
                    'i',
                    'i.'.$key,
                    '=',
                    'd.id',
                )
                ->whereNull('d.deleted_at')
                ->whereRaw("d.{$column} <> COALESCE(i.total, 0)")
                ->select('d.id', 'd.document_no', 'd.'.$column.' as stored')
                ->selectRaw('COALESCE(i.total, 0) as lines')
                ->get();

            foreach ($rows as $row) {
                $failures[] = [
                    'what' => $row->document_no,
                    'says' => __('total says :stored, its lines add to :real', [
                        'stored' => money((int) $row->stored),
                        'real' => money((int) $row->lines),
                    ]),
                    'url' => route($route, $row->id),
                ];
            }
        }

        // Section 6: grand_total = total - discount, and nothing else.
        $wrongGrand = DB::table('purchases')
            ->whereNull('deleted_at')
            ->whereRaw('grand_total <> total_amount - discount_amount')
            ->select('id', 'document_no', 'grand_total', 'total_amount', 'discount_amount')
            ->get();

        foreach ($wrongGrand as $row) {
            $failures[] = [
                'what' => $row->document_no,
                'says' => __('grand total says :stored, but :total less :discount is :real', [
                    'stored' => money((int) $row->grand_total),
                    'total' => money((int) $row->total_amount, false),
                    'discount' => money((int) $row->discount_amount, false),
                    'real' => money((int) $row->total_amount - (int) $row->discount_amount),
                ]),
                'url' => route('purchases.show', $row->id),
            ];
        }

        return $this->result(
            key: 'document_totals',
            group: __('Money'),
            title: __('Every invoice and purchase adds up to its own lines'),
            because: __('A total that disagrees with its lines is money reported that was never charged, or charged and never reported. It also feeds straight into the ledger and the profit report.'),
            examined: (int) DB::table('sales')->whereNull('deleted_at')->count()
                + (int) DB::table('purchases')->whereNull('deleted_at')->count(),
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    /** The same rule for the documents that give money back. */
    private function returnTotalsMatchTheirLines(): array
    {
        $failures = [];

        foreach ([
            ['sale_returns', 'sale_return_items', 'sale_return_id', 'quantity * unit_price', 'sale-returns.show'],
            ['purchase_returns', 'purchase_return_items', 'purchase_return_id', 'quantity * unit_price - discount_share', 'purchase-returns.show'],
        ] as [$table, $items, $key, $sum, $route]) {
            $rows = DB::table($table.' as d')
                ->leftJoinSub(
                    DB::table($items)->select($key)->selectRaw("SUM({$sum}) as total")->groupBy($key),
                    'i',
                    'i.'.$key,
                    '=',
                    'd.id',
                )
                ->whereNull('d.deleted_at')
                ->whereRaw('d.total_amount <> COALESCE(i.total, 0)')
                ->select('d.id', 'd.document_no', 'd.total_amount as stored')
                ->selectRaw('COALESCE(i.total, 0) as lines')
                ->get();

            foreach ($rows as $row) {
                $failures[] = [
                    'what' => $row->document_no,
                    'says' => __('total says :stored, its lines add to :real', [
                        'stored' => money((int) $row->stored),
                        'real' => money((int) $row->lines),
                    ]),
                    'url' => route($route, $row->id),
                ];
            }
        }

        return $this->result(
            key: 'return_totals',
            group: __('Money'),
            title: __('Every return adds up to what was actually sent back'),
            because: __('A return total decides the refund and the credit note. A purchase return also carries its share of the supplier discount, which is the part most easily lost.'),
            examined: (int) DB::table('sale_returns')->whereNull('deleted_at')->count()
                + (int) DB::table('purchase_returns')->whereNull('deleted_at')->count(),
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    /** Section 8 rule 4: nothing may be paid for more than it cost. */
    private function nothingIsOverpaid(): array
    {
        $failures = [];

        foreach ([
            ['sales', 'sale', 'total_amount', 'sales.show'],
            ['purchases', 'purchase', 'grand_total', 'purchases.show'],
        ] as [$table, $type, $column, $route]) {
            $rows = DB::table($table.' as d')
                ->joinSub(
                    DB::table('payments')->select('payable_id')
                        ->selectRaw("SUM(case when direction = 'in' then amount else -amount end) as paid")
                        ->where('payable_type', $type)->whereNull('deleted_at')
                        ->groupBy('payable_id'),
                    'p',
                    'p.payable_id',
                    '=',
                    'd.id',
                )
                ->whereNull('d.deleted_at')
                ->whereRaw("p.paid > d.{$column}")
                ->select('d.id', 'd.document_no', 'd.'.$column.' as owed', 'p.paid')
                ->get();

            foreach ($rows as $row) {
                $failures[] = [
                    'what' => $row->document_no,
                    'says' => __(':paid paid against :owed owed', [
                        'paid' => money((int) $row->paid),
                        'owed' => money((int) $row->owed),
                    ]),
                    'url' => route($route, $row->id),
                ];
            }
        }

        return $this->result(
            key: 'overpaid',
            group: __('Money'),
            title: __('Nothing has been paid for more than it came to'),
            because: __('More paid than owed is either a refund that was never recorded or a payment entered against the wrong document. Both leave somebody short and neither shows up anywhere else.'),
            examined: (int) DB::table('payments')->whereNull('deleted_at')->count(),
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    /** Section 4: the Cash Customer "must always be paid in full (no loan)." */
    private function theCashCustomerOwesNothing(): array
    {
        $failures = [];

        $cash = Customer::withoutGlobalScopes()->where('is_system', true)->get();

        if ($cash->isEmpty()) {
            $failures[] = [
                'what' => __('Cash Customer'),
                'says' => __('missing — the sale form has no default for a walk-in buyer'),
                'url' => null,
            ];
        }

        foreach ($cash as $customer) {
            if ((int) $customer->balance !== 0) {
                $failures[] = [
                    'what' => $customer->name,
                    'says' => __('owes :amount, and a walk-in buyer never leaves owing anything', [
                        'amount' => money((int) $customer->balance),
                    ]),
                    'url' => route('customers.show', $customer->id),
                ];
            }
        }

        return $this->result(
            key: 'cash_customer',
            group: __('Money'),
            title: __('The walk-in customer owes nothing'),
            because: __('Every walk-in sale is recorded against one built-in customer who always pays in full. A balance there is a real sale that somebody was let leave without paying, filed under a name that belongs to nobody.'),
            examined: $cash->count(),
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    // =================================================================
    // Documents
    // =================================================================

    /** Section 10b assertion 6. Two documents with one name is two events the records cannot tell apart. */
    private function noDocumentNumberIsUsedTwice(): array
    {
        $failures = [];
        $examined = 0;

        foreach ([
            'sales', 'purchases', 'sale_returns', 'purchase_returns',
            'payments', 'stock_adjustments', 'expenses',
        ] as $table) {
            $examined += (int) DB::table($table)->count();

            $rows = DB::table($table)
                ->select('document_no')
                ->selectRaw('COUNT(*) as times')
                ->groupBy('document_no')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($rows as $row) {
                $failures[] = [
                    'what' => $row->document_no,
                    'says' => __('used :times times in :table', [
                        'times' => $row->times, 'table' => $this->say($table),
                    ]),
                    'url' => null,
                ];
            }
        }

        return $this->result(
            key: 'duplicate_numbers',
            group: __('Documents'),
            title: __('No document number is used twice'),
            because: __('A number is how a document is found, printed and argued about. Two documents sharing one is two different events that the records can no longer tell apart.'),
            examined: $examined,
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    /**
     * The counter has to be ahead of every number already used.
     *
     * Deleted rows keep their numbers — Section 8b: "document_no stays consumed,
     * numbers are never reused" — so this reads the whole table, trashed rows
     * included. A counter that fell behind is not wrong yet; it is a duplicate
     * document number the next time somebody sells something, which is the one
     * failure above that cannot be repaired afterwards.
     */
    private function countersAreAheadOfWhatIsUsed(): array
    {
        $failures = [];

        $tables = [
            'INV' => 'sales', 'PUR' => 'purchases', 'SRT' => 'sale_returns',
            'PRT' => 'purchase_returns', 'PAY' => 'payments',
            'ADJ' => 'stock_adjustments', 'EXP' => 'expenses',
        ];

        $counters = DocumentCounter::pluck('next_number', 'prefix');

        foreach ($tables as $prefix => $table) {
            // Zero-padded to five, so the highest string is the highest number.
            $highest = DB::table($table)->where('document_no', 'like', $prefix.'-%')->max('document_no');

            if ($highest === null) {
                continue;
            }

            $used = (int) substr((string) $highest, strlen($prefix) + 1);
            $next = (int) ($counters[$prefix] ?? 0);

            if ($next <= $used) {
                $failures[] = [
                    'what' => $prefix,
                    'says' => __('next number is :next, but :highest already exists', [
                        'next' => $next, 'highest' => $highest,
                    ]),
                    'url' => null,
                ];
            }
        }

        return $this->result(
            key: 'counters',
            group: __('Documents'),
            title: __('The next document number is ahead of every one already used'),
            because: __('A counter that has fallen behind is not wrong yet — it becomes a duplicate number the next time somebody sells something. This is the one failure on this page that is easier to catch now than to repair later.'),
            examined: count($tables),
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    /** Nothing can come back that never went out. */
    private function nothingIsReturnedMoreThanOnce(): array
    {
        $failures = [];

        foreach ([
            ['sale_items', 'sales', 'sale_id', 'sales.show'],
            ['purchase_items', 'purchases', 'purchase_id', 'purchases.show'],
        ] as [$items, $parent, $key, $route]) {
            $rows = DB::table($items.' as i')
                ->join($parent.' as d', 'd.id', '=', 'i.'.$key)
                ->join('products as p', 'p.id', '=', 'i.product_id')
                ->whereNull('d.deleted_at')
                ->whereColumn('i.quantity_returned', '>', 'i.quantity')
                ->select('d.id', 'd.document_no', 'p.name', 'i.quantity', 'i.quantity_returned')
                ->get();

            foreach ($rows as $row) {
                $failures[] = [
                    'what' => $row->document_no.' · '.$row->name,
                    'says' => __(':returned returned out of :sold', [
                        'returned' => number_format((int) $row->quantity_returned),
                        'sold' => number_format((int) $row->quantity),
                    ]),
                    'url' => route($route, $row->id),
                ];
            }
        }

        return $this->result(
            key: 'over_returned',
            group: __('Documents'),
            title: __('Nothing has come back more times than it went out'),
            because: __('More returned than sold puts units on the shelf that were never bought, and refunds money that was never taken.'),
            examined: (int) DB::table('sale_items')->count() + (int) DB::table('purchase_items')->count(),
            // (Every remaining line belongs to a live document: deleting one
            //  removes its lines as part of the reversal.)
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    /** Section 4: status is derived from the lines, never typed. */
    private function statusesMatchTheirLines(): array
    {
        $failures = [];

        foreach ([
            ['sales', 'sale_items', 'sale_id', 'sales.show'],
            ['purchases', 'purchase_items', 'purchase_id', 'purchases.show'],
        ] as [$table, $items, $key, $route]) {
            $rows = DB::table($table.' as d')
                ->join($items.' as i', 'i.'.$key, '=', 'd.id')
                ->whereNull('d.deleted_at')
                ->groupBy('d.id', 'd.document_no', 'd.status')
                ->havingRaw(
                    "d.status <> case when SUM(i.quantity_returned) <= 0 then 'active' ".
                    "when SUM(i.quantity_returned) >= SUM(i.quantity) then 'returned' ".
                    "else 'partly_returned' end"
                )
                ->select('d.id', 'd.document_no', 'd.status')
                ->selectRaw('SUM(i.quantity) as sold, SUM(i.quantity_returned) as returned')
                ->get();

            foreach ($rows as $row) {
                $should = match (true) {
                    (int) $row->returned <= 0 => 'active',
                    (int) $row->returned >= (int) $row->sold => 'returned',
                    default => 'partly_returned',
                };

                $failures[] = [
                    'what' => $row->document_no,
                    'says' => __('marked :is, but :returned of :sold came back, so it is :should', [
                        'is' => $this->statusWord($row->status), 'should' => $this->statusWord($should),
                        'returned' => number_format((int) $row->returned),
                        'sold' => number_format((int) $row->sold),
                    ]),
                    'url' => route($route, $row->id),
                ];
            }
        }

        return $this->result(
            key: 'derived_status',
            group: __('Documents'),
            title: __('Every document is labelled by what its lines actually say'),
            because: __('Returned, partly returned and active are worked out from the lines, never typed. A label that disagrees with them is only a wrong word on a screen, and recalculating fixes it.'),
            examined: (int) DB::table('sales')->whereNull('deleted_at')->count()
                + (int) DB::table('purchases')->whereNull('deleted_at')->count(),
            failures: $failures,
            kind: self::REBUILDABLE,
        );
    }

    // =================================================================
    // Links
    // =================================================================

    /**
     * The joins the database is not watching.
     *
     * Section 5 lists these as "polymorphic — no database FK, enforce in code":
     * a payment names its document by a type and a number, and MySQL has no way
     * to check that the document exists. Everywhere else a foreign key refuses
     * the delete; here nothing does, so this is the only thing standing between
     * a hand-edited row and a payment against an invoice that is not there.
     *
     * Soft-deleted parents are fine and expected — a movement belonging to a
     * deleted sale is exactly how a reversal is recorded. Only a parent that is
     * gone from the table entirely counts.
     */
    private function everyLinkPointsAtSomething(): array
    {
        $links = [
            ['payments', 'payable_type', 'payable_id', [
                'sale' => 'sales', 'purchase' => 'purchases',
                'sale_return' => 'sale_returns', 'purchase_return' => 'purchase_returns',
            ], __('a payment')],

            ['account_transactions', 'accountable_type', 'accountable_id', [
                'customer' => 'customers', 'supplier' => 'suppliers',
            ], __('a ledger entry')],

            ['account_transactions', 'reference_type', 'reference_id', [
                'sale' => 'sales', 'purchase' => 'purchases',
                'sale_return' => 'sale_returns', 'purchase_return' => 'purchase_returns',
                'payment' => 'payments', 'expense' => 'expenses',
            ], __('a ledger entry')],

            ['stock_movements', 'reference_type', 'reference_id', [
                'sale' => 'sales', 'purchase' => 'purchases',
                'sale_return' => 'sale_returns', 'purchase_return' => 'purchase_returns',
                'adjustment' => 'stock_adjustments',
            ], __('a stock movement')],

            ['stock_movements', 'reference_type', 'reference_item_id', [
                'sale' => 'sale_items', 'purchase' => 'purchase_items',
                'sale_return' => 'sale_return_items', 'purchase_return' => 'purchase_return_items',
            ], __('a stock movement')],

            ['stock_batches', 'source_type', 'source_id', [
                'purchase' => 'purchases', 'adjustment' => 'stock_adjustments',
            ], __('a batch')],
        ];

        $failures = [];
        $examined = 0;

        foreach ($links as [$table, $typeColumn, $idColumn, $map, $label]) {
            foreach ($map as $type => $target) {
                $examined += $count = (int) DB::table($table)
                    ->where($typeColumn, $type)->whereNotNull($idColumn)->count();

                if ($count === 0) {
                    continue;
                }

                $orphans = DB::table($table.' as t')
                    ->leftJoin($target.' as x', 'x.id', '=', 't.'.$idColumn)
                    ->where('t.'.$typeColumn, $type)
                    ->whereNotNull('t.'.$idColumn)
                    ->whereNull('x.id')
                    ->select('t.id', 't.'.$idColumn.' as points_at')
                    ->get();

                foreach ($orphans as $orphan) {
                    $failures[] = [
                        'what' => $label.' · #'.$orphan->id,
                        'says' => __('points at :target #:id, which is not there', [
                            'target' => $this->say($target), 'id' => $orphan->points_at,
                        ]),
                        'url' => null,
                    ];
                }
            }
        }

        return $this->result(
            key: 'orphan_links',
            group: __('Links'),
            title: __('Every record still points at something that exists'),
            because: __('These are the links Section 5 marks "no database FK, enforce in code" — a payment names its invoice by number, and the database has no way to check the invoice is there. Everywhere else a foreign key refuses. Here nothing does.'),
            examined: $examined,
            failures: $failures,
            kind: self::SERIOUS,
        );
    }

    // =================================================================

    /**
     * A stored slug — 'sale_return', 'customer' — in the shop's own words.
     *
     * Written as literals inside __() rather than as __($slug), because
     * translations:check reads the source by tokenising it: a key built at
     * runtime is a key it cannot see, and a string it cannot see ships in
     * English while the command reports 100% translated.
     */
    private function say(string $slug): string
    {
        return match ($slug) {
            'sale', 'sales' => __('Sale'),
            'purchase', 'purchases' => __('Purchase'),
            'sale_return', 'sale_returns' => __('Sale return'),
            'purchase_return', 'purchase_returns' => __('Purchase return'),
            'adjustment', 'stock_adjustments' => __('Adjustment'),
            'payment', 'payments' => __('Payment'),
            'expense', 'expenses' => __('Expense'),
            'customer', 'customers' => __('Customer'),
            'supplier', 'suppliers' => __('Supplier'),
            'sale_items' => __('Sale line'),
            'purchase_items' => __('Purchase line'),
            'sale_return_items' => __('Sale return line'),
            'purchase_return_items' => __('Purchase return line'),
            default => Str::headline($slug),
        };
    }

    /** The three words a document's status can be. Literals, for the same reason. */
    private function statusWord(string $status): string
    {
        return match ($status) {
            'active' => __('Active'),
            'partly_returned' => __('Partly returned'),
            'returned' => __('Returned'),
            default => $status,
        };
    }

    /**
     * One check's finding, in the shape the page reads.
     *
     * @param  list<array{what: string, says: string, url: string|null}>  $failures
     */
    private function result(
        string $key,
        string $group,
        string $title,
        string $because,
        int $examined,
        array $failures,
        string $kind,
        ?string $repair = null,
        ?int $failedOverride = null,
    ): array {
        $failed = $failedOverride ?? count($failures);

        return [
            'key' => $key,
            'group' => $group,
            'title' => $title,
            'because' => $because,
            'examined' => $examined,
            'failed' => $failed,
            'severity' => $failed === 0 ? self::OK : $kind,
            'examples' => array_slice($failures, 0, self::EXAMPLES),
            'more' => max(0, $failed - self::EXAMPLES),
            'repair' => $failed === 0 ? null : $repair,
        ];
    }
}
