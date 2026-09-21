<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Technician;
use App\Rules\Amount;
use App\Services\ActivityLogger;
use App\Services\RepairService;
use App\Support\MoneyInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'technicians' => Technician::active()->orderBy('name')->get(),
            'repair' => null,
            'customers' => Customer::orderBy('name')->get(),
            'products' => $this->sellable(),
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
                technician: ($data['technician_id'] ?? null) === null ? null : Technician::find($data['technician_id']),
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
        $repair->load('customer', 'items.product', 'sale', 'user', 'technician');

        return view('repairs.show', [
            'lens' => $request->user()->lens(),
            'technicians' => Technician::active()->orderBy('name')->get(),
            'repair' => $repair,
        ]);
    }

    public function edit(Request $request, Repair $repair): View
    {
        return view('repairs.form', [
            'lens' => $request->user()->lens(),
            'technicians' => Technician::active()->orderBy('name')->get(),
            'repair' => $repair->load('items.product'),
            'customers' => Customer::orderBy('name')->get(),
            'products' => $this->sellable(),
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
        $request->validate(['technician_id' => ['nullable', 'exists:technicians,id']]);

        try {
            $this->repairs->accept(
                repair: $repair,
                user: $request->user(),
                technician: $request->filled('technician_id') ? Technician::find($request->input('technician_id')) : null,
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
        return view('repairs.print.ticket', [
            'repair' => $repair->load('customer', 'items.product', 'technician'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Repair $repair = null): array
    {
        $lens = $request->user()->lens();

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'technician_id' => ['nullable', 'exists:technicians,id'],
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
