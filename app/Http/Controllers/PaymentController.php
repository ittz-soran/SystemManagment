<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Support\DocumentLink;
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
            'payable' => $payable,
            'payableType' => $request->string('payable_type')->toString(),
            'context' => $this->describe($payable),
            'backUrl' => DocumentLink::url($payable, $request->user()),
        ]);
    }

    /**
     * Section 4: a payment is the only record of money actually moving, so it
     * gets a page of its own — the document it settles, the party it moved
     * between, and what it left owing.
     */
    public function show(Payment $payment): View
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
            'payment' => $payment,
            'party' => $this->party($payment->payable),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payable_type' => ['required', Rule::in(['sale', 'purchase', 'sale_return', 'purchase_return'])],
            'payable_id' => ['required', 'integer'],
            // Section 4: the amount is always positive; direction carries the sign.
            'amount' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in([Payment::DIRECTION_IN, Payment::DIRECTION_OUT])],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payable = $this->resolvePayable($data['payable_type'], (int) $data['payable_id']);

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
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->to($this->documentUrl($payable))
            ->with('success', __('Payment recorded'));
    }

    public function edit(Payment $payment): View
    {
        $payable = $payment->payable;

        return view('payments.edit', [
            'payment' => $payment,
            'payable' => $payable,
            'party' => $this->party($payable),
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
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in([Payment::DIRECTION_IN, Payment::DIRECTION_OUT])],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

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
                    amount: (int) $payment->amount,
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

        DB::transaction(function () use ($payment, $payable, $request) {
            $account = $this->settlingAccount($payable, $payment->direction);

            if ($account) {
                $this->ledger->post(
                    account: $account,
                    type: AccountTransaction::TYPE_PAYMENT,
                    // The opposite of what recording it posted.
                    amount: (int) $payment->amount,
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
     * @return array{party: string, total: int, paid: int, due: int, direction: string, hint: string}
     */
    private function describe(Model $payable): array
    {
        return match (true) {
            $payable instanceof Sale => [
                'party' => $payable->customer->displayName(),
                'total' => $payable->total_amount,
                'paid' => $payable->amountPaid(),
                'due' => $payable->amountDue(),
                'direction' => Payment::DIRECTION_IN,
                'hint' => __('The customer is paying you, so the money comes in.'),
            ],
            $payable instanceof Purchase => [
                'party' => $payable->supplier->name,
                'total' => $payable->grand_total,
                'paid' => $payable->amountPaid(),
                'due' => $payable->amountDue(),
                // Money leaving the till.
                'direction' => Payment::DIRECTION_OUT,
                'hint' => __('Paying the supplier is money leaving the till, and reduces what you owe them.'),
            ],
            $payable instanceof SaleReturn => [
                'party' => $payable->customer->displayName(),
                'total' => $payable->total_amount,
                'paid' => 0,
                'due' => 0,
                'direction' => Payment::DIRECTION_OUT,
                'hint' => __('A cash refund is money leaving the till.'),
            ],
            default => [
                'party' => $payable->supplier->name,
                'total' => $payable->total_amount,
                'paid' => 0,
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
