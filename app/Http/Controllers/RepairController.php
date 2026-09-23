<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\Amount;
use App\Services\ActivityLogger;
use App\Services\LabelService;
use App\Services\RepairService;
use App\Support\MoneyInput;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * The workshop book — Soran, 2026-09-20.
 *
 * ⚠️ Nothing on this screen moves stock or money. A job holds its parts and
 * leaves them on the shelf; collecting makes an ordinary sale, and that sale is
 * what does everything the shop cares about. See `RepairService` for why a
 * second path through FIFO was refused.
 */
class RepairController extends Controller
{
    public function __construct(
        private RepairService $repairs,
        private ActivityLogger $log,
    ) {}

    public function index(Request $request): View
    {
        /*
         * Open jobs by default. A repairs list is a bench rather than a
         * history: what a shopkeeper opens it to ask is "what am I holding",
         * and a year of collected tickets buries that on the first page.
         */
        $status = $request->string('status')->toString() ?: 'open';

        $repairs = Repair::query()
            ->with('customer', 'items.product', 'sale')
            ->when($status === 'open', fn ($q) => $q->open())
            ->when(in_array($status, Repair::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('document_no', 'like', '%'.$request->input('search').'%')
                ->orWhere('device', 'like', '%'.$request->input('search').'%')
                ->orWhere('identifier', 'like', '%'.$request->input('search').'%')
                ->orWhere('fault', 'like', '%'.$request->input('search').'%')
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('phone', 'like', '%'.$request->input('search').'%'))))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('repairs.index', [
            'lens' => $request->user()->lens(),
            'repairs' => $repairs,
            'status' => $status,
            'counts' => $this->counts(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('repairs.form', [
            'lens' => $request->user()->lens(),
            'technicians' => $this->technicians(),
            'repair' => null,
            'customers' => Customer::orderBy('name')->get(),
            'products' => $this->sellable(),
            'suppliers' => $this->suppliers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $repair = $this->repairs->create(
                customer: Customer::findOrFail($data['customer_id']),
                device: $data['device'],
                fault: $data['fault'],
                user: $request->user(),
                receivedAt: now(),
                identifier: $data['identifier'] ?? null,
                conditionNote: $data['condition_note'] ?? null,
                promisedFor: $data['promised_for'] === null ? null : Carbon::parse($data['promised_for']),
                estimate: $data['estimate'],
                note: $data['note'] ?? null,
                lines: $data['lines'],
                technician: ($data['technician_id'] ?? null) === null ? null : User::find($data['technician_id']),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->log->log('create', 'repairs', $repair->id,
            __('Took in :device for repair (:number)', ['device' => $repair->device, 'number' => $repair->document_no]),
            user: $request->user());

        return redirect()->route('repairs.show', $repair)
            ->with('success', __('Repair :number taken in', ['number' => $repair->document_no]));
    }

    public function show(Request $request, Repair $repair): View
    {
        $repair->load('customer', 'items.product', 'sale', 'user', 'technician', 'approvals');

        return view('repairs.show', [
            'lens' => $request->user()->lens(),
            'technicians' => $this->technicians(),
            'repair' => $repair,

            // Raw figures. What this reader is allowed to see of them is
            // `cost_seen()`'s answer, asked in the view beside the price.
            'cost' => $this->repairs->costOf($repair),
        ]);
    }

    public function edit(Request $request, Repair $repair): View
    {
        return view('repairs.form', [
            'lens' => $request->user()->lens(),
            'technicians' => $this->technicians(),
            'repair' => $repair->load('items.product'),
            'customers' => Customer::orderBy('name')->get(),
            'products' => $this->sellable(),
            'suppliers' => $this->suppliers(),
        ]);
    }

    public function update(Request $request, Repair $repair): RedirectResponse
    {
        $data = $this->validated($request, $repair);

        try {
            if (! $repair->canBeModified()) {
                throw new RuntimeException(__('This repair has been collected and paid for. Delete :invoice first if it is wrong.', [
                    'invoice' => $repair->sale?->document_no ?? __('the sale'),
                ]));
            }

            $before = $repair->only(['device', 'identifier', 'fault', 'condition_note', 'promised_for', 'estimate', 'status']);

            $repair->fill([
                'customer_id' => $data['customer_id'],
                'technician_id' => $data['technician_id'] ?? null,
                'device' => $data['device'],
                'identifier' => $data['identifier'] ?? null,
                'fault' => $data['fault'],
                'condition_note' => $data['condition_note'] ?? null,
                'promised_for' => $data['promised_for'],
                'estimate' => $data['estimate'],
                'note' => $data['note'] ?? null,
            ])->save();

            $this->repairs->setLines($repair, $data['lines']);

            $this->log->log('update', 'repairs', $repair->id,
                __('Changed repair :number', ['number' => $repair->document_no]), $before, $request->user());
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('repairs.show', $repair)->with('success', __('Repair saved'));
    }

    /**
     * The customer says yes, and the ticket becomes real.
     *
     * ⚠️ Its own action rather than a status, because it is what freezes the
     * price they agreed to and the warranty offered on each line.
     */
    public function accept(Request $request, Repair $repair): RedirectResponse
    {
        $request->validate([
            'technician_id' => ['nullable', Rule::in($this->technicians()->modelKeys())],
            'channel' => ['required', 'in:counter,phone'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->repairs->accept(
                repair: $repair,
                user: $request->user(),
                technician: $request->filled('technician_id') ? User::find($request->input('technician_id')) : null,
                channel: $request->string('channel')->toString(),
                note: $request->string('note')->toString() ?: null,
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->log->log('update', 'repairs', $repair->id,
            __('Customer accepted repair :number', ['number' => $repair->document_no]), user: $request->user());

        return redirect()->route('repairs.ticket', $repair);
    }

    /** Along the bench: received → in progress → ready. */
    public function status(Request $request, Repair $repair): RedirectResponse
    {
        $request->validate(['status' => ['required', 'string']]);

        try {
            $this->repairs->setStatus($repair, $request->string('status')->toString(), $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Repair updated'));
    }

    /**
     * The customer collects, and the job becomes a sale.
     *
     * ⚠️ The only action here that touches stock or money, and it does so by
     * asking SaleService rather than by doing it.
     */
    public function collect(Request $request, Repair $repair): RedirectResponse
    {
        $data = $request->validate([
            'amount_paid' => ['nullable', new Amount],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
        ]);

        try {
            $sale = $this->repairs->collect(
                repair: $repair,
                user: $request->user(),
                amountPaid: (int) MoneyInput::fromRequest($request, 'amount_paid', $request->user()->lens()),
                paymentMethod: $data['payment_method'],
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->log->log('create', 'repairs', $repair->id,
            __('Collected repair :number as :invoice', ['number' => $repair->document_no, 'invoice' => $sale->document_no]),
            user: $request->user());

        return redirect()->route('sales.show', $sale)
            ->with('success', __('Collected. Invoice :number', ['number' => $sale->document_no]));
    }

    /** Handed back unmended — an outcome, not a mistake. */
    public function handBack(Request $request, Repair $repair): RedirectResponse
    {
        $request->validate(['why' => ['nullable', 'string', 'max:500']]);

        try {
            $this->repairs->handBack($repair, $request->user(), $request->string('why')->toString() ?: null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->log->log('update', 'repairs', $repair->id,
            __('Handed back :number unrepaired', ['number' => $repair->document_no]), user: $request->user());

        return back()->with('success', __('Handed back to the customer'));
    }

    public function destroy(Request $request, Repair $repair): RedirectResponse
    {
        if (! $repair->canBeModified()) {
            return back()->with('error', __('This repair has been collected and paid for. Delete :invoice first if it is wrong.', [
                'invoice' => $repair->sale?->document_no ?? __('the sale'),
            ]));
        }

        $this->log->log('delete', 'repairs', $repair->id,
            __('Deleted repair :number', ['number' => $repair->document_no]), user: $request->user());

        $repair->delete();

        return redirect()->route('repairs.index')->with('success', __('Repair deleted'));
    }

    /** The ticket the customer walks away with. */
    public function ticket(Request $request, Repair $repair): View
    {
        $repair->load('customer', 'items.product', 'technician', 'approvals');

        return view('repairs.print.ticket', [
            'repair' => $repair,
            /*
             * ⚠️ Sized for a thumb and a phone camera, not for a shelf label.
             * The customer photographs this ticket and brings the photo back,
             * so the bars have to survive a screen, a camera and a scanner —
             * 60mm wide and 12mm tall, roughly half the printable width.
             */
            'barcode' => app(LabelService::class)->svg($repair->document_no, 60, 12),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Repair $repair = null): array
    {
        $lens = $request->user()->lens();

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'technician_id' => ['nullable', Rule::in($this->technicians()->modelKeys())],
            'device' => ['required', 'string', 'max:160'],
            'identifier' => ['nullable', 'string', 'max:80'],
            'fault' => ['required', 'string', 'max:1000'],
            'condition_note' => ['nullable', 'string', 'max:1000'],
            'promised_for' => ['nullable', 'date'],
            'estimate' => ['nullable', new Amount],
            'note' => ['nullable', 'string', 'max:1000'],

            'lines' => ['array'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', new Amount],
        ]);

        // Section 2b: stored as the base-currency integer, read through the
        // reader's lens if they have one.
        $data['estimate'] = ($data['estimate'] ?? null) === null || $data['estimate'] === ''
            ? null
            : MoneyInput::fromRequest($request, 'estimate', $lens, $repair?->estimate);

        $data['promised_for'] = $data['promised_for'] ?? null;

        $data['lines'] = collect($data['lines'] ?? [])->map(fn ($line, $index) => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (int) $line['quantity'],
            'unit_price' => (int) MoneyInput::fromRequest($request, "lines.{$index}.unit_price", $lens),
        ])->values()->all();

        return $data;
    }

    /** Parts and labour alike: both are products, which is what makes the sale ordinary. */
    private function sellable()
    {
        return Product::query()
            ->whereIn('kind', [Product::KIND_STOCK, Product::KIND_SERVICE])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'kind', 'sale_price', 'quantity', 'warranty_days']);
    }

    /**
     * Buy a part the shop has not got, without leaving the repair screen.
     *
     * Soran, 2026-09-23: *"do purchase directly on repair page and supplier
     * should other added suppliers just select supplier and input cost and
     * price and warranty without going to purchase page but purchase is cash
     * and paid"*.
     *
     * ⚠️ Answers JSON, because the form it is called from is half filled in
     * with a broken phone described in it. A redirect would throw that away to
     * add one row.
     */
    public function buyPart(Request $request): JsonResponse
    {
        $lens = $request->user()->lens();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Suppliers the shop set up. Not invented per purchase: one nobody
            // set up is one nobody can be paid or reconciled with.
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'unit_cost' => ['required', new Amount],
            'sale_price' => ['required', new Amount],
            'warranty_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        try {
            $bought = $this->repairs->buyPart(
                name: $data['name'],
                supplier: Supplier::findOrFail($data['supplier_id']),
                quantity: (int) $data['quantity'],
                // Section 2b: what was typed is read through the lens and
                // stored as the base-currency integer, exactly as every other
                // figure on this form is.
                unitCost: (int) MoneyInput::fromRequest($request, 'unit_cost', $lens),
                salePrice: (int) MoneyInput::fromRequest($request, 'sale_price', $lens),
                warrantyDays: $data['warranty_days'] === null ? null : (int) $data['warranty_days'],
                user: $request->user(),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product = $bought['product'];

        $this->log->log('create', 'repairs', $product->id,
            __('Bought :quantity × :name for a repair (:number)', [
                'quantity' => $data['quantity'],
                'name' => $product->name,
                'number' => $bought['purchase']->document_no,
            ]),
            user: $request->user());

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'sale_price' => $product->sale_price,
                'warranty_days' => $product->warranty_days,
            ],
            'purchase_no' => $bought['purchase']->document_no,
            'message' => __('Bought on :number and added to the job.', [
                'number' => $bought['purchase']->document_no,
            ]),
        ]);
    }

    /**
     * The people a job may be given to — Soran, 2026-09-22.
     *
     * ⚠️ **Also what the form is validated against.** `exists:users,id` would
     * let anybody who can edit the HTML put the shop's accountant on a
     * television repair, and the list on screen would have said nothing about
     * it. The same query answers both questions, so what is offered and what is
     * accepted cannot drift apart.
     *
     * @return Collection<int, User>
     */
    private function technicians(): Collection
    {
        return User::canRepair()->orderBy('name')->get(['id', 'name', 'phone']);
    }

    /**
     * The suppliers a part can be bought from on this screen.
     *
     * ⚠️ Walk-ins are left out. That list is the public — people the shop buys
     * second-hand phones FROM — and a screen bought for a job comes from a
     * trade counter, not from a customer who wandered in.
     *
     * @return Collection<int, Supplier>
     */
    private function suppliers(): Collection
    {
        return Supplier::where('is_active', true)->companies()
            ->orderBy('name')->get(['id', 'name', 'phone']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = ['open' => Repair::query()->open()->count()];

        foreach (Repair::STATUSES as $status) {
            $counts[$status] = Repair::where('status', $status)->count();
        }

        return $counts;
    }
}
