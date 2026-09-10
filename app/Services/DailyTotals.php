<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Support\CalendarNames;
use Illuminate\Support\Carbon;

/**
 * What the shop did on each day of a period.
 *
 * Four screens draw the same trend chart from these — the reports page, the
 * dashboard, the cash card and a product's own page — so the arithmetic lives
 * here once rather than four times. A figure that is worked out twice is a
 * figure that eventually disagrees with itself, and a shopkeeper who finds two
 * numbers for Tuesday stops believing both.
 *
 * Two kinds of reading come out of this class and they behave nothing alike:
 *
 * A **flow** is money that moved on a day — sold, bought, made. It starts at
 * zero every morning, so a chart of it is drawn from zero.
 *
 * A **level** is what something was worth at closing time — the shelf, mostly.
 * It carries in from the day before and never goes near zero, so it needs a
 * window of its own and has to be drawn apart from the flows. Putting the two
 * on one axis is the mistake this whole chart exists to avoid.
 *
 * Every day in the range comes back, including the ones with nothing on them.
 * Skipping the quiet days draws a line straight from Thursday to Sunday and
 * makes a closed weekend look like ordinary trade.
 */
class DailyTotals
{
    /**
     * Sold, bought and made, day by day.
     *
     * Sales and purchases are net of what came back, and the return lands on
     * the day it came back rather than the day of the sale — Monday's takings
     * were real when they were taken.
     *
     * The cost comes back rather than the profit, deliberately. Section 2 lets a
     * shop show a counter assistant a marked-up cost or none at all, and profit
     * is a subtraction away from cost — so a screen that showed the true profit
     * beside a marked-up cost would hand back the real figure by arithmetic.
     * The caller runs the cost through the reader's own setting and derives
     * profit from what came out, with `profit()` below.
     *
     * @return array{sales: array<string, int>, purchases: array<string, int>, cost: array<string, int>}
     */
    public function flows(Carbon $from, Carbon $to): array
    {
        $sold = $this->sum(Sale::query(), 'sale_date', 'total_amount', $from, $to);
        $soldBack = $this->sum(SaleReturn::query(), 'return_date', 'total_amount', $from, $to);

        $bought = $this->sum(Purchase::query(), 'purchase_date', 'grand_total', $from, $to);
        $boughtBack = $this->sum(PurchaseReturn::query(), 'return_date', 'total_amount', $from, $to);

        // Outgoing movements are stored negative and a return puts the cost
        // back, so one signed sum over both kinds is the day's real cost of
        // goods sold.
        $cost = $this->sum(
            StockMovement::whereIn('reference_type', [
                StockMovement::REF_SALE,
                StockMovement::REF_SALE_RETURN,
            ]),
            'occurred_at',
            '-quantity * unit_cost',
            $from,
            $to,
        );

        $sales = [];
        $purchases = [];
        $soldCost = [];

        foreach ($this->days($from, $to) as $day) {
            $sales[$day] = ($sold[$day] ?? 0) - ($soldBack[$day] ?? 0);
            $purchases[$day] = ($bought[$day] ?? 0) - ($boughtBack[$day] ?? 0);
            $soldCost[$day] = $cost[$day] ?? 0;
        }

        return ['sales' => $sales, 'purchases' => $purchases, 'cost' => $soldCost];
    }

    /**
     * Revenue less cost, day by day.
     *
     * Fed the cost the reader is allowed to work from rather than the stored
     * one, so what is on screen adds up: a marked-up cost gives the profit that
     * markup implies, and the real one is not a subtraction away.
     *
     * @param  array<string, int>  $sales
     * @param  array<string, int>  $cost
     * @return array<string, int>
     */
    public function profit(array $sales, array $cost): array
    {
        $profit = [];

        foreach ($sales as $day => $revenue) {
            $profit[$day] = $revenue - ($cost[$day] ?? 0);
        }

        return $profit;
    }

    /**
     * What the shelf was worth at the close of each day, at FIFO cost.
     *
     * A level, and the one figure here that cannot be answered from inside the
     * period: today's stock is everything the shop ever took in less everything
     * it ever let out. So the running total starts before the window and is
     * carried forward into it. Every movement records the cost of its own
     * units, which is what makes this answerable at all — an average cost would
     * make yesterday's shelf worth whatever today's price happens to be.
     *
     * @return array<string, int>
     */
    public function stockValue(Carbon $from, Carbon $to): array
    {
        $moved = StockMovement::selectRaw('occurred_at as day, SUM(quantity * unit_cost) as total')
            ->where('occurred_at', '<=', $to)
            ->groupBy('occurred_at')
            ->orderBy('occurred_at')
            ->pluck('total', 'day');

        $start = $from->toDateString();
        $carried = 0;
        $perDay = [];

        foreach ($moved as $when => $total) {
            $day = $this->dayOf($when);

            if ($day < $start) {
                $carried += (int) $total;

                continue;
            }

            $perDay[$day] = ($perDay[$day] ?? 0) + (int) $total;
        }

        $running = $carried;
        $value = [];

        foreach ($this->days($from, $to) as $day) {
            $running += $perDay[$day] ?? 0;
            $value[$day] = $running;
        }

        return $value;
    }

    /**
     * Money in and money out, day by day.
     *
     * Payments rather than sales: this is the till, not the ledger. A sale on
     * credit is revenue today and cash next month, and the difference between
     * those two is the whole reason a shop with good figures can still be
     * unable to pay a supplier on Thursday.
     *
     * @return array{in: array<string, int>, out: array<string, int>}
     */
    public function cash(Carbon $from, Carbon $to): array
    {
        $in = $this->sum(
            Payment::where('direction', Payment::DIRECTION_IN), 'paid_at', 'amount', $from, $to
        );

        $out = $this->sum(
            Payment::where('direction', Payment::DIRECTION_OUT), 'paid_at', 'amount', $from, $to
        );

        $moneyIn = [];
        $moneyOut = [];

        foreach ($this->days($from, $to) as $day) {
            $moneyIn[$day] = $in[$day] ?? 0;
            $moneyOut[$day] = $out[$day] ?? 0;
        }

        return ['in' => $moneyIn, 'out' => $moneyOut];
    }

    /**
     * One product's units and takings, day by day, net of returns.
     *
     * The question a shopkeeper actually asks on a product's page is whether it
     * still moves. A page of a hundred movement rows answers it eventually; a
     * line answers it at a glance.
     *
     * @return array{units: array<string, int>, revenue: array<string, int>}
     */
    public function forProduct(Product $product, Carbon $from, Carbon $to): array
    {
        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.product_id', $product->id)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->groupBy('sales.sale_date')
            ->selectRaw('sales.sale_date as day')
            ->selectRaw('SUM(sale_items.quantity - sale_items.quantity_returned) as units')
            ->selectRaw('SUM((sale_items.quantity - sale_items.quantity_returned) * sale_items.unit_price) as revenue')
            ->get();

        $units = [];
        $revenue = [];

        foreach ($rows as $row) {
            $day = $this->dayOf($row->day);
            $units[$day] = ($units[$day] ?? 0) + (int) $row->units;
            $revenue[$day] = ($revenue[$day] ?? 0) + (int) $row->revenue;
        }

        $perDay = ['units' => [], 'revenue' => []];

        foreach ($this->days($from, $to) as $day) {
            $perDay['units'][$day] = $units[$day] ?? 0;
            $perDay['revenue'][$day] = $revenue[$day] ?? 0;
        }

        return $perDay;
    }

    /**
     * The date axis: a short label per day, and the weekday behind it.
     *
     * The label is numerals rather than a month name — seven of these sit
     * across a narrow axis and a Kurdish month name is four words long — and
     * the chart is drawn left-to-right in every language, so the date reads
     * that way too. The weekday is the reader's own, and only the crosshair
     * shows it: "Friday" is usually the whole explanation for a flat day.
     *
     * @param  list<string>  $days
     * @return array{labels: list<string>, notes: list<string>}
     */
    public function axis(array $days): array
    {
        $weekdays = CalendarNames::weekdays();

        $labels = [];
        $notes = [];

        foreach ($days as $day) {
            $date = Carbon::parse($day);

            $labels[] = $date->format('j/n');
            $notes[] = $weekdays[$date->dayOfWeek] ?? '';
        }

        return ['labels' => $labels, 'notes' => $notes];
    }

    /**
     * Every day in the range, as `Y-m-d`.
     *
     * @return list<string>
     */
    public function days(Carbon $from, Carbon $to): array
    {
        $days = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * One signed total per calendar day, keyed `Y-m-d`.
     *
     * @return array<string, int>
     */
    private function sum($query, string $dateColumn, string $amount, Carbon $from, Carbon $to): array
    {
        $totals = [];

        $rows = $query->whereBetween($dateColumn, [$from, $to])
            ->groupBy($dateColumn)
            ->selectRaw("$dateColumn as day, SUM($amount) as total")
            ->pluck('total', 'day');

        foreach ($rows as $when => $total) {
            $day = $this->dayOf($when);
            $totals[$day] = ($totals[$day] ?? 0) + (int) $total;
        }

        return $totals;
    }

    /**
     * The calendar day a stored value belongs to.
     *
     * MySQL hands back `2026-09-10` from a DATE column while SQLite hands back
     * the `2026-09-10 00:00:00` Laravel wrote into it, and `occurred_at` is a
     * real timestamp in both. Matching the whole value would draw a flat line
     * on half the shops.
     */
    private function dayOf($value): string
    {
        return substr((string) $value, 0, 10);
    }
}
