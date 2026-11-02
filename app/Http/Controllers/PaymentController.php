<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Rules\Amount;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Support\DocumentLink;
use App\Support\Money;
use App\Support\MoneyInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Section 9: record money in or out against a specific sale or purchase —
 * a customer paying an invoice (`in`), paying a supplier (`out`), a cash refund
 * to a customer (`out`), or cash back from a supplier (`in`).
 */
class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private LedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        $payments = Payment::with('user', 'payable')
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->input('method')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where('document_no', 'like', '%'.$request->input('search').'%'))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 4: reports read the direction, so cash in and cash out stay
        // separate and legible.
        $base = fn () => Payment::query()
            // The same period the list is showing, or the totals would count
            // rows the reader cannot see.
            ->visible($request->boolean('archived'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->date('to')));

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) Payment::archivedOnly()->count();

        return view('payments.index', [
            'archivedCount' => $archivedCount,
            'payments' => $payments,
            'lens' => $request->user()->lens(),
            'totalIn' => (int) $base()->where('direction', Payment::DIRECTION_IN)->sum('amount'),
            'totalOut' => (int) $base()->where('direction', Payment::DIRECTION_OUT)->sum('amount'),
        ]);
    }

    public function create(Request $request): View
    {
        $payable = $this->resolvePayable(
            $request->string('payable_type')->toString(),
            $request->integer('payable_id'),
        );

        return view('payments.create', [
            // Section 2b — which currency this person types and reads in.
            'lens' => $request->user()->lens(),
            'payable' => $payable,
            'payableType' => $request->string('payable_type')->toString(),
            'context' => $this->describe($payable),
            'owed' => $this->owedInDollars($payable),
            'backUrl' => DocumentLink::url($payable, $request->user()),
        ]);
    }

    /**
     * Section 4: a payment is the only record of money actually moving, so it
     * gets a page of its own — the document it settles, the party it moved
     * between, and what it left owing.
     */
    public function show(Request $request, Payment $payment): View
    {
        // The payable is polymorphic, so the party hanging off it has to be
        // named per type — preventLazyLoading turns a miss into an exception,
        // which is the point: a financial page should never half-load.
        $payment->load(['user', 'payable' => fn ($morph) => $morph->morphWith([
            Sale::class => ['customer'],
            SaleReturn::class => ['customer'],
            Purchase::class => ['supplier'],
            PurchaseReturn::class => ['supplier'],
        ])]);

        return view('payments.show', [
            'lens' => $request->user()->lens(),
            'payment' => $payment,
            'party' => $this->party($payment->payable),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'payable_type' => ['required', Rule::in(['sale', 'purchase', 'sale_return', 'purchase_return'])],
            'payable_id' => ['required', 'integer'],
        ]);

        $payable = $this->resolvePayable($request->string('payable_type')->toString(), $request->integer('payable_id'));

        // Section 6: a purchase owed in dollars is paid in dollars.
        if ($payable instanceof Purchase && $payable->owedIn() !== null) {
            return $this->storeInDollars($request, $payable);
        }

        $lens = $request->user()->lens();

        $data = $request->validate([
            'payable_type' => ['required', Rule::in(['sale', 'purchase', 'sale_return', 'purchase_return'])],
            'payable_id' => ['required', 'integer'],
            // Section 4: the amount is always positive; direction carries the
            // sign. Section 2b: checked in whichever currency it was typed in,
            // with the floor still meaning one base unit.
            'amount' => ['required', new Amount($lens, min: 1)],
            'direction' => ['required', Rule::in([Payment::DIRECTION_IN, Payment::DIRECTION_OUT])],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Section 2b: what the box held, as the base-currency integer stored.
        // A create has nothing that could be untouched, so no original.
        $data['amount'] = MoneyInput::fromRequest($request, 'amount', $lens);

        try {
            DB::transaction(function () use ($data, $payable, $request) {
                $this->payments->record(
                    payable: $payable,
                    amount: (int) $data['amount'],
                    direction: $data['direction'],
                    user: $request->user(),
                    method: $data['payment_method'],
                    paidAt: Carbon::parse($data['paid_at']),
                    notes: $data['notes'] ?? null,
                );

                $account = $this->settlingAccount($payable, $data['direction']);

                if ($account) {
                    $this->ledger->post(
                        account: $account,
                        type: AccountTransaction::TYPE_PAYMENT,
                        amount: -1 * (int) $data['amount'],
                        reference: $payable,
                        user: $request->user(),
                        notes: $payable->document_no,
                    );
                }
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', problem($e));
        }

        return redirect()
            ->to($this->documentUrl($payable))
            ->with('success', __('Payment recorded'));
    }

    public function edit(Request $request, Payment $payment): View
    {
        $payable = $payment->payable;

        return view('payments.edit', [
            'lens' => $request->user()->lens(),
            'payment' => $payment,
            'payable' => $payable,
            'party' => $this->party($payable),
            'owed' => $this->inDollars($payment) ? $this->owedInDollars($payable, $payment) : null,
            'backUrl' => $payable ? $this->documentUrl($payable) : route('payments.index'),
        ]);
    }

    /**
     * Correct a payment that was written down wrong.
     *
     * Section 8's shape, the one every other document already uses: reverse
     * what it did to the ledger, then post it again with the new figures,
     * inside one transaction. Not a difference — 20,000 corrected to 15,000 is
     * the whole 20,000 put back and a fresh 15,000 taken, so the balance ends
     * where it would have if the figure had been right the first time.
     *
     * The document it is against does not change. A payment pointed at a
     * different invoice is a different payment, and the screen does not offer
     * it — the same rule the stock adjustment follows about its product.
     */
    public function update(Request $request, Payment $payment): RedirectResponse
    {
        if ($this->inDollars($payment)) {
            return $this->updateInDollars($request, $payment);
        }

        $lens = $request->user()->lens();

        $data = $request->validate([
            'amount' => ['required', new Amount($lens, min: 1)],
            'direction' => ['required', Rule::in([Payment::DIRECTION_IN, Payment::DIRECTION_OUT])],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * ⚠️ Section 2b's untouched-field rule. An edit opened in dollars shows
         * 15,000 dinars as $11.36, and $11.36 back at 1,320 is 14,995. Somebody
         * correcting only the date would move the money by five dinars and post
         * a ledger reversal for it. The figure is only re-read when the box
         * itself was typed into.
         */
        $data['amount'] = MoneyInput::fromRequest($request, 'amount', $lens, $payment->amount);

        $paidAt = Carbon::parse($data['paid_at']);

        // Both dates: the day it was on and the day it is moving to. Taking a
        // payment out of a closed period is as much a change to closed books as
        // putting one into it.
        foreach ([$payment->paid_at, $paidAt] as $date) {
            if (books_closed_on($date)) {
                return back()->withInput()->with('error', __('Locked: this date is in a closed period.'));
            }
        }

        $payable = $payment->payable;

        DB::transaction(function () use ($payment, $payable, $data, $paidAt, $request) {
            // Off, exactly as a delete would take it off.
            $was = $this->settlingAccount($payable, $payment->direction);

            if ($was) {
                $this->ledger->post(
                    account: $was,
                    type: AccountTransaction::TYPE_PAYMENT,
                    amount: $payment->settled(),
                    reference: $payable,
                    user: $request->user(),
                    notes: __('Reversal of :document', ['document' => $payment->document_no]),
                );
            }

            $payment->forceFill([
                'amount' => (int) $data['amount'],
                'direction' => $data['direction'],
                'payment_method' => $data['payment_method'],
                'paid_at' => $paidAt,
                'notes' => $data['notes'] ?? null,
            ])->save();

            // And on again, as recording it would put it on.
            $now = $this->settlingAccount($payable, $payment->direction);

            if ($now) {
                $this->ledger->post(
                    account: $now,
                    type: AccountTransaction::TYPE_PAYMENT,
                    amount: -1 * (int) $payment->amount,
                    reference: $payable,
                    user: $request->user(),
                    notes: $payable->document_no,
                );
            }
        });

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', __('Payment saved'));
    }

    /**
     * A payment on a purchase owed in dollars — Section 6, Soran, 2026-11-02:
     * *"when i pay, then the system should keep the dinars against the
     * dollars"*. Typed in dollars, at the rate of the day; the dinars that
     * left are those dollars at that rate, and the supplier's account moves by
     * the dinars of the purchase they settled. The rest is the payment's rate
     * difference, which the profit report carries.
     */
    private function storeInDollars(Request $request, Purchase $purchase): RedirectResponse
    {
        $currency = $purchase->owedIn();
        $data = $request->validate($this->dollarRules($currency));
        $foreign = (int) Money::readAt($data['foreign_amount'], $currency->decimals);

        try {
            DB::transaction(function () use ($purchase, $data, $foreign, $request) {
                // Locked, so two people paying the last dollar at once cannot
                // both close it.
                $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                $split = $this->split($locked, $foreign, (int) $data['exchange_rate']);

                $this->payments->record(
                    payable: $locked,
                    amount: $split['amount'],
                    // Money back from the supplier goes through a purchase
                    // return — on a dollar purchase a payment is money out.
                    direction: Payment::DIRECTION_OUT,
                    user: $request->user(),
                    method: $data['payment_method'],
                    paidAt: Carbon::parse($data['paid_at']),
                    notes: $data['notes'] ?? null,
                    foreignAmount: $foreign,
                    exchangeRate: (int) $data['exchange_rate'],
                    rateDifference: $split['difference'],
                );

                $this->bringTheLedgerTo($locked, $request->user(), $locked->document_no);
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', problem($e));
        }

        return redirect()
            ->to($this->documentUrl($purchase))
            ->with('success', __('Payment recorded'));
    }

    /**
     * Correct a dollar payment: put back the dinars it settled, and settle
     * again by the same rule, as if the others were all there were.
     */
    private function updateInDollars(Request $request, Payment $payment): RedirectResponse
    {
        /** @var Purchase $purchase */
        $purchase = $payment->payable;
        $currency = $purchase->owedIn();
        $data = $request->validate($this->dollarRules($currency));
        $foreign = (int) Money::readAt($data['foreign_amount'], $currency->decimals);
        $paidAt = Carbon::parse($data['paid_at']);

        foreach ([$payment->paid_at, $paidAt] as $date) {
            if (books_closed_on($date)) {
                return back()->withInput()->with('error', __('Locked: this date is in a closed period.'));
            }
        }

        try {
            DB::transaction(function () use ($payment, $purchase, $data, $foreign, $paidAt, $request) {
                $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

                $split = $this->split($locked, $foreign, (int) $data['exchange_rate'], $payment);

                $payment->forceFill([
                    'amount' => $split['amount'],
                    'foreign_amount' => $foreign,
                    'exchange_rate' => (int) $data['exchange_rate'],
                    'rate_difference' => $split['difference'],
                    'payment_method' => $data['payment_method'],
                    'paid_at' => $paidAt,
                    'notes' => $data['notes'] ?? null,
                ])->save();

                $this->bringTheLedgerTo($locked, $request->user(), $payment->document_no);
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', problem($e));
        }

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', __('Payment saved'));
    }

    /**
     * Bring the supplier's account to what this dollar purchase still owes.
     *
     * ⚠️ **Not "post minus what the payment settled".** A supplier's balance
     * stops at zero, so a payment that went past it only ever applied part of
     * itself: PUR-00059's 24,000, paid before this, applied 23,360 against a
     * supplier owed nothing else. Putting back the payment's own figure would
     * invent 640 owed. So the purchase's postings are summed as the ledger
     * applied them and moved to what is owed now — the purchase less what its
     * payments settled — the way an edited purchase is re-posted. An old
     * payment corrected here comes out right whichever way it went in.
     */
    private function bringTheLedgerTo(Purchase $purchase, User $user, string $notes): void
    {
        $supplier = $purchase->supplier()->firstOrFail();

        $applied = (int) AccountTransaction::query()
            ->where('accountable_type', 'supplier')
            ->where('accountable_id', $supplier->id)
            ->where('reference_type', 'purchase')
            ->where('reference_id', $purchase->id)
            ->sum('amount');

        $this->ledger->post(
            account: $supplier,
            type: AccountTransaction::TYPE_PAYMENT,
            amount: ($purchase->grand_total - $purchase->amountPaid()) - $applied,
            reference: $purchase,
            user: $user,
            notes: $notes,
        );
    }

    /** @return array{amount: int, settled: int, difference: int} */
    private function split(Purchase $purchase, int $foreign, int $rate, ?Payment $except = null): array
    {
        $split = $purchase->settle($foreign, $rate, $except);

        // A payment is at least one dinar — a cent at a rate of one is nothing.
        if ($split['amount'] < 1) {
            throw new RuntimeException(__('That comes to less than 1 :code.', ['code' => Money::base()->code]));
        }

        return $split;
    }

    /** @return array<string, array<int, mixed>> */
    private function dollarRules(Currency $currency): array
    {
        return [
            'foreign_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,'.$currency->decimals],
            'exchange_rate' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Money out on a purchase owed in dollars — the payments the dollar box edits. */
    private function inDollars(Payment $payment): bool
    {
        return $payment->payable instanceof Purchase
            && $payment->payable->owedIn() !== null
            && $payment->direction === Payment::DIRECTION_OUT;
    }

    /**
     * What a payment screen needs to take dollars: what is owed in them, and
     * the two rates — the purchase's own, and today's, which the box starts at.
     *
     * @return array<string, mixed>|null
     */
    private function owedInDollars(?Model $payable, ?Payment $except = null): ?array
    {
        if (! $payable instanceof Purchase || ($currency = $payable->owedIn()) === null) {
            return null;
        }

        return [
            'currency' => $currency,
            'total' => $payable->foreign_total,
            'paid' => $payable->foreignPaid($except),
            'returned' => $payable->foreignCredited(),
            'due' => (int) $payable->foreignDue($except),
            // The dinars the closing payment settles — what the box's sum
            // shows when the dollars typed reach what is owed.
            'dueInDinars' => $payable->amountDue($except),
            'purchaseRate' => (int) $payable->exchange_rate,
            'todaysRate' => max(1, (int) round($currency->rate / Money::RATE_SCALE)),
        ];
    }

    /**
     * The account a payment moves, or null when it moves none.
     *
     * The ledger only follows the two cases that settle a debt: a customer
     * paying their invoice, or the shop paying a supplier. A refund's effect was
     * already recorded by the return that caused it, and money going the other
     * way on a document — change handed back when a cart is edited down — is
     * the till moving, not the debt.
     *
     * Both recording and deleting a payment read this one method, so a deletion
     * reverses exactly what the recording posted and never a penny else.
     */
    private function settlingAccount(?Model $payable, string $direction): ?Model
    {
        return match (true) {
            $payable instanceof Sale && $direction === Payment::DIRECTION_IN
                => $payable->customer()->firstOrFail(),
            $payable instanceof Purchase && $direction === Payment::DIRECTION_OUT
                => $payable->supplier()->firstOrFail(),
            default => null,
        };
    }

    /** The customer or supplier the document belongs to, or null. */
    private function party(?Model $payable): ?Model
    {
        return match (true) {
            $payable instanceof Sale, $payable instanceof SaleReturn => $payable->customer,
            $payable instanceof Purchase, $payable instanceof PurchaseReturn => $payable->supplier,
            default => null,
        };
    }

    /**
     * Section 8b: a delete is a reversal plus a hidden record, never a way to
     * skip the reversal. A payment that settled a debt put money against it;
     * removing the payment puts the debt back.
     */
    public function destroy(Request $request, Payment $payment): RedirectResponse
    {
        if (books_closed_on($payment->paid_at)) {
            return back()->with('error', __('Locked: this date is in a closed period.'));
        }

        $payable = $payment->payable;

        if ($this->inDollars($payment)) {
            DB::transaction(function () use ($payment, $payable, $request) {
                $locked = Purchase::whereKey($payable->id)->lockForUpdate()->firstOrFail();
                $payment->delete();
                $this->bringTheLedgerTo($locked, $request->user(),
                    __('Reversal of :document', ['document' => $payment->document_no]));
            });

            return redirect()
                ->to(after_delete(route('payments.show', $payment), $this->documentUrl($payable)))
                ->with('success', __('Payment deleted'));
        }

        DB::transaction(function () use ($payment, $payable, $request) {
            $account = $this->settlingAccount($payable, $payment->direction);

            if ($account) {
                $this->ledger->post(
                    account: $account,
                    type: AccountTransaction::TYPE_PAYMENT,
                    // The opposite of what recording it posted: what it
                    // settled, which on a dollar purchase is not what left.
                    amount: $payment->settled(),
                    reference: $payable,
                    user: $request->user(),
                    notes: __('Reversal of :document', ['document' => $payment->document_no]),
                );
            }

            $payment->delete();
        });

        // The payment's own page has just stopped existing. The document it
        // was against is the natural place to land, and the reader's own
        // previous page beats it when they came from somewhere else.
        return redirect()
            ->to(after_delete(
                route('payments.show', $payment),
                $payable ? $this->documentUrl($payable) : route('payments.index'),
            ))
            ->with('success', __('Payment deleted'));
    }

    private function resolvePayable(string $type, int $id): Model
    {
        return match ($type) {
            'sale' => Sale::with('customer')->findOrFail($id),
            'purchase' => Purchase::with('supplier')->findOrFail($id),
            'sale_return' => SaleReturn::with('customer')->findOrFail($id),
            'purchase_return' => PurchaseReturn::with('supplier')->findOrFail($id),
            default => throw new RuntimeException(__('Unknown document type.')),
        };
    }

    /**
     * What this payment is for, in the interface's own words rather than the
     * schema's — the totals and the sensible default direction.
     *
     * ⚠️ `returned` is here so the three figures ADD UP on the screen. Without
     * it a shopkeeper sees a 180,000 total, nothing paid, and 135,000 still due
     * — three true numbers that look like a mistake.
     *
     * @return array{party: string, total: int, paid: int, returned: int, due: int, direction: string, hint: string}
     */
    private function describe(Model $payable): array
    {
        return match (true) {
            $payable instanceof Sale => [
                'party' => $payable->customer->displayName(),
                'total' => $payable->total_amount,
                'paid' => $payable->amountPaid(),
                'returned' => $payable->creditedByReturns(),
                'due' => $payable->amountDue(),
                'direction' => Payment::DIRECTION_IN,
                'hint' => __('The customer is paying you, so the money comes in.'),
            ],
            $payable instanceof Purchase => [
                'party' => $payable->supplier->name,
                'total' => $payable->grand_total,
                'paid' => $payable->amountPaid(),
                'returned' => $payable->creditedByReturns(),
                'due' => $payable->amountDue(),
                // Money leaving the till.
                'direction' => Payment::DIRECTION_OUT,
                'hint' => __('Paying the supplier is money leaving the till, and reduces what you owe them.'),
            ],
            $payable instanceof SaleReturn => [
                'party' => $payable->customer->displayName(),
                'total' => $payable->total_amount,
                'paid' => 0,
                // Nothing is ever returned against a return.
                'returned' => 0,
                'due' => 0,
                'direction' => Payment::DIRECTION_OUT,
                'hint' => __('A cash refund is money leaving the till.'),
            ],
            default => [
                'party' => $payable->supplier->name,
                'total' => $payable->total_amount,
                'paid' => 0,
                'returned' => 0,
                'due' => 0,
                'direction' => Payment::DIRECTION_IN,
                'hint' => __('Cash back from the supplier comes into the till.'),
            ],
        };
    }

    private function documentUrl(Model $payable): string
    {
        return match (true) {
            $payable instanceof Sale => route('sales.show', $payable),
            $payable instanceof Purchase => route('purchases.show', $payable),
            $payable instanceof SaleReturn => route('sale-returns.show', $payable),
            default => route('purchase-returns.show', $payable),
        };
    }
}
