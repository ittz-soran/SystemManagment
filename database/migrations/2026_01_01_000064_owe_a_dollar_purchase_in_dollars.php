<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dollar purchase is owed in dollars — PROJECT_DOC Section 6, Soran,
 * 2026-11-02: *"i have PUR-00059 Product A 4qty at 4$ total 16$ but i pay for
 * this after 20days and curency are changed at this days"*.
 *
 * Signed columns throughout, and the difference most of all: a rate that fell
 * is a gain, written below zero. MariaDB refuses a negative into an unsigned
 * column where SQLite takes it, and arithmetic on one in SQL wraps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            // Both or neither: the currency the supplier's paper is in, and its
            // total in that currency's minor units (cents).
            $table->string('foreign_currency', 8)->nullable()->after('exchange_rate');
            $table->bigInteger('foreign_total')->nullable()->after('foreign_currency');
        });

        Schema::table('payments', function (Blueprint $table) {
            // A payment on a dollar purchase: the dollars typed, in cents, and
            // the rate of the day they were paid at. `amount` stays the dinars
            // that left the till, as on every other payment.
            $table->bigInteger('foreign_amount')->nullable()->after('amount');
            $table->unsignedInteger('exchange_rate')->nullable()->after('foreign_amount');
            // Dinars the rate cost beyond the purchase's own rate: above zero a
            // loss, below it a gain. The supplier's account moved by
            // `amount - rate_difference`.
            $table->bigInteger('rate_difference')->default(0)->after('exchange_rate');
        });

        $this->backfill();
    }

    /**
     * Every purchase already written in a foreign currency gets its total in
     * that currency, from its own lines — the same arithmetic the service uses.
     * Its payments keep their dinars and settle at the purchase's own rate, with
     * no difference: nothing written before this changes.
     *
     * Plain queries rather than the models, which will have moved on by the
     * time an old shop runs this.
     */
    private function backfill(): void
    {
        $base = (string) (DB::table('settings')->where('key', 'currency_base')->value('value') ?: 'IQD');
        $places = DB::table('currencies')->pluck('decimals', 'code')->map(fn ($d) => (int) $d)->all();

        DB::table('purchases')->whereNotNull('exchange_rate')->where('exchange_rate', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($purchases) use ($base, $places) {
                foreach ($purchases as $purchase) {
                    $lines = DB::table('purchase_items')->where('purchase_id', $purchase->id)->get();

                    $code = $lines->pluck('entered_currency')
                        ->first(fn ($c) => $c !== null && $c !== '' && $c !== $base && isset($places[$c]));

                    if ($code === null) {
                        continue;
                    }

                    $minor = 10 ** $places[$code];
                    $rate = (int) $purchase->exchange_rate;
                    $foreign = fn (int $dinars): int => (int) round($dinars * $minor / $rate);

                    $total = 0;

                    foreach ($lines as $line) {
                        $unit = $line->entered_amount !== null
                            ? (int) $line->entered_amount
                            : $foreign((int) $line->unit_price);
                        $total += (int) $line->quantity * $unit;
                    }

                    $total -= $foreign((int) $purchase->discount_amount);

                    DB::table('purchases')->where('id', $purchase->id)
                        ->update(['foreign_currency' => $code, 'foreign_total' => $total]);

                    $this->backfillPayments($purchase, $rate, $foreign, $total);
                }
            });
    }

    /**
     * Each payment in dollars at the purchase's own rate. When the payments
     * settled the purchase exactly, the last one takes the cent a rounding
     * would otherwise leave owing — it is the closing payment.
     */
    private function backfillPayments(object $purchase, int $rate, Closure $foreign, int $total): void
    {
        $payments = DB::table('payments')
            ->where('payable_type', 'purchase')->where('payable_id', $purchase->id)
            ->orderBy('paid_at')->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            DB::table('payments')->where('id', $payment->id)->update([
                'foreign_amount' => $foreign((int) $payment->amount),
                'exchange_rate' => $rate,
                'rate_difference' => 0,
            ]);
        }

        $live = $payments->whereNull('deleted_at');
        $sign = fn ($p) => $p->direction === 'out' ? 1 : -1;
        $paid = $live->sum(fn ($p) => $sign($p) * (int) $p->amount);

        $credited = -(int) DB::table('account_transactions')
            ->where('reference_type', 'purchase_return')
            ->whereIn('reference_id', DB::table('purchase_returns')
                ->where('purchase_id', $purchase->id)->whereNull('deleted_at')->select('id'))
            ->sum('amount');

        $last = $live->where('direction', 'out')->last();

        if ($last === null || (int) $purchase->grand_total - $paid - $credited !== 0) {
            return;
        }

        $creditedForeign = (int) $purchase->grand_total > 0
            ? (int) round($credited * $total / (int) $purchase->grand_total)
            : 0;

        $others = $live->reject(fn ($p) => $p->id === $last->id)
            ->sum(fn ($p) => $sign($p) * $foreign((int) $p->amount));

        DB::table('payments')->where('id', $last->id)->update([
            'foreign_amount' => max(0, $total - $creditedForeign - $others),
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['foreign_amount', 'exchange_rate', 'rate_difference']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['foreign_currency', 'foreign_total']);
        });
    }
};
