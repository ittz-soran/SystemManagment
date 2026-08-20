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
        $payments = Payment::with('user')
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
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->date('to')));

        return view('payments.index', [
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

                // The ledger only moves for the two cases that settle a debt:
                // a customer paying their invoice, or the shop paying a supplier.
                // A refund's ledger effect was already recorded by the return.
                $account = match (true) {
                    $payable instanceof Sale => $payable->customer()->firstOrFail(),
                    $payable instanceof Purchase => $payable->supplier()->firstOrFail(),
                    default => null,
                };

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
                'party' => $payable->customer->name,
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
                'party' => $payable->customer->name,
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
