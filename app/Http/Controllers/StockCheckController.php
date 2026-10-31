<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockCheck;
use App\Models\StockCheckLine;
use App\Models\StockMovement;
use App\Models\StockRoom;
use App\Models\User;
use App\Services\StockCheckService;
use App\Support\Digits;
use App\Support\MoneyInput;
use App\Support\Sections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * The stock check — PROJECT_DOC Section 9. Soran, 2026-10-30: *"stock check
 * manually, for ex in Stock Check show PD-17L-UK In stock, batches,
 * movments... and Show Confirm, Skip, Wrong"*.
 *
 * Rounds and the report under `stock_checks.view`; starting one and answering
 * in it under `stock_checks.count`; making the ADJ also needs
 * `stock_adjustments.create`, which the service checks. A round sent to
 * someone (`stock_checks.assign`) is seen by them with `count` alone, and
 * answered by them only — StockCheck::answerableBy().
 */
class StockCheckController extends Controller
{
    public function __construct(private StockCheckService $checks) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $seesAll = $user->hasPermission('stock_checks.view');

        return view('stock-checks.index', [
            // Without `view`, only the rounds sent to this person.
            'checks' => StockCheck::with(['category', 'room', 'user', 'assignee'])
                ->when(! $seesAll, fn ($q) => $q->where('assigned_to', $user->id))
                ->orderByRaw('finished_at IS NULL DESC')->orderByDesc('id')
                ->paginate(25),
            'seesAll' => $seesAll,
            'mayStart' => $seesAll && $user->hasPermission('stock_checks.count'),
            'maySend' => $seesAll && $user->hasPermission('stock_checks.assign'),
            'counters' => $seesAll && $user->hasPermission('stock_checks.assign') ? $this->counters($user) : collect(),
            'toFix' => $seesAll ? StockCheckLine::where('status', StockCheckLine::WRONG)->where('fix', StockCheckLine::FIX_LATER)->count() : 0,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'rooms' => Sections::isOn('stock_rooms') ? StockRoom::query()->inOrder()->get() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:'.implode(',', [StockCheck::SCOPE_ALL, StockCheck::SCOPE_CATEGORY, StockCheck::SCOPE_ROOM])],
            'category_id' => ['nullable', 'required_if:scope,category', 'exists:categories,id'],
            'room_id' => ['nullable', 'required_if:scope,room', 'exists:stock_rooms,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = isset($data['assigned_to']) ? User::find($data['assigned_to']) : null;

        // Starting one to count yourself needs `count`; sending one needs
        // `assign`, which the service checks.
        if ($assignee === null && ! $request->user()->hasPermission('stock_checks.count')) {
            return back()->withInput()->with('error', __('Choose who to send it to.'));
        }

        try {
            $check = $this->checks->start(
                $data['scope'],
                isset($data['category_id']) ? (int) $data['category_id'] : null,
                isset($data['room_id']) ? (int) $data['room_id'] : null,
                $request->boolean('with_empty'),
                $request->boolean('blind'),
                $request->user(),
                $assignee,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', problem($e));
        }

        // Sent: back to the round, to watch it. Started: straight to counting.
        return $assignee
            ? redirect()->route('stock-checks.show', $check)->with('success', __('Sent to :name.', ['name' => $assignee->name]))
            : redirect()->route('stock-checks.next', $check);
    }

    /** The round's own page, which is also its report. */
    public function show(Request $request, StockCheck $stockCheck): View
    {
        $this->maySee($request, $stockCheck);

        return view('stock-checks.show', $this->report($request, $stockCheck));
    }

    public function print(Request $request, StockCheck $stockCheck): View
    {
        $this->maySee($request, $stockCheck);

        return view('print.stock-check', $this->report($request, $stockCheck));
    }

    /** On to the next product, or back to the round when there is none. */
    public function next(Request $request, StockCheck $stockCheck): RedirectResponse
    {
        $this->mayAnswer($request, $stockCheck);

        $line = $stockCheck->isFinished() ? null : $this->checks->next($stockCheck);

        return $line
            ? redirect()->route('stock-checks.line', [$stockCheck, $line])
            : redirect()->route('stock-checks.show', $stockCheck)->with('success', __('Every product in this round has an answer.'));
    }

    /** Scan to open: a barcode or code typed in a round opens that product. */
    public function scan(Request $request, StockCheck $stockCheck): RedirectResponse
    {
        $this->mayAnswer($request, $stockCheck);

        $code = Digits::english(trim($request->string('code')->toString()));

        $product = $code === '' ? null : Product::where('barcode', $code)->orWhere('sku', $code)->first();
        $line = $product ? $stockCheck->lines()->where('product_id', $product->id)->first() : null;

        if ($line === null) {
            return back()->with('error', $product
                ? __(':product is not in this round.', ['product' => $product->name])
                : __('No product has the code :code.', ['code' => $code]));
        }

        return redirect()->route('stock-checks.line', [$stockCheck, $line]);
    }

    /** One product, as the shelf is being counted. */
    public function line(Request $request, StockCheck $stockCheck, StockCheckLine $line): View
    {
        $this->mayAnswer($request, $stockCheck);

        $product = $line->product;

        return view('stock-checks.line', [
            'check' => $stockCheck,
            'line' => $line,
            'product' => $product->load('category'),
            'progress' => $stockCheck->progress(),
            'system' => $this->checks->systemQuantity($stockCheck, $product),
            // A blind count shows the figure once the count has been typed —
            // and still after a reload, from the first count kept.
            'counted' => session('counted') ?? ($stockCheck->blind && in_array($line->status, [StockCheckLine::PENDING, StockCheckLine::SKIPPED], true)
                ? $line->first_count
                : null),
            'rooms' => Sections::isOn('stock_rooms') && $stockCheck->scope !== StockCheck::SCOPE_ROOM
                ? StockBatch::where('product_id', $product->id)->withStock()
                    ->selectRaw('room_id, SUM(quantity_remaining) as held')->groupBy('room_id')
                    ->pluck('held', 'room_id')
                    ->mapWithKeys(fn ($held, $roomId) => [StockRoom::withTrashed()->find($roomId)?->name ?? '?' => (int) $held])
                : collect(),
            'batches' => StockBatch::where('product_id', $product->id)->withStock()
                ->when($stockCheck->scope === StockCheck::SCOPE_ROOM, fn ($q) => $q->where('room_id', $stockCheck->room_id))
                ->with('source')->fifoOrder()->get(),
            'movements' => StockMovement::where('product_id', $product->id)
                ->with('reference')->orderByDesc('occurred_at')->orderByDesc('sequence')->limit(10)->get(),
            'lastCheck' => StockCheckLine::where('product_id', $product->id)->whereNotNull('checked_at')
                ->whereKeyNot($line->id)->with(['checker', 'stockCheck'])->latest('checked_at')->first(),
            'canAdjust' => $request->user()->hasPermission('stock_adjustments.create')
                && ($stockCheck->scope !== StockCheck::SCOPE_ROOM || $stockCheck->room_id === StockRoom::main()->id),
            'latestCost' => $this->checks->latestCost($product),
            'lens' => $request->user()->lens(),
        ]);
    }

    public function confirm(Request $request, StockCheck $stockCheck, StockCheckLine $line): RedirectResponse
    {
        $this->mayAnswer($request, $stockCheck);

        return $this->then($stockCheck, $line, fn () => $this->checks->confirm($line, $request->integer('seen'), $request->user()));
    }

    public function skip(Request $request, StockCheck $stockCheck, StockCheckLine $line): RedirectResponse
    {
        $this->mayAnswer($request, $stockCheck);

        return $this->then($stockCheck, $line, fn () => $this->checks->skip($line, $request->user()));
    }

    /**
     * A blind count: a count that agrees is confirmed; one that does not opens
     * the product again with everything shown and the count already in, to be
     * answered as wrong.
     */
    public function count(Request $request, StockCheck $stockCheck, StockCheckLine $line): RedirectResponse
    {
        $this->mayAnswer($request, $stockCheck);

        $data = $request->validate(['counted' => ['required', 'integer', 'min:0'], 'seen' => ['required', 'integer']]);
        $now = $this->checks->systemQuantity($stockCheck, $line->product);

        // ⚠️ The same refusal as every answer: a sale while the shelf was being
        // counted would otherwise show as one too many on the shelf.
        if ((int) $data['seen'] !== $now) {
            return redirect()->route('stock-checks.line', [$stockCheck, $line])->with('error',
                __('The stock of this product changed while it was being counted — it now says :now. Count it again.', ['now' => $now]));
        }

        if ((int) $data['counted'] === $now) {
            return $this->then($stockCheck, $line, fn () => $this->checks->confirm($line, $now, $request->user()));
        }

        // ⚠️ Kept before the figure is shown — PROJECT_DOC Section 9, the
        // first count. The report sets it beside whatever is answered next.
        try {
            $this->checks->firstCount($line, (int) $data['counted'], $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('stock-checks.line', [$stockCheck, $line])->with('error', problem($e));
        }

        return redirect()->route('stock-checks.line', [$stockCheck, $line])->with('counted', (int) $data['counted']);
    }

    /** Wrong: the count, and the ADJ now or the To fix list. */
    public function answer(Request $request, StockCheck $stockCheck, StockCheckLine $line): RedirectResponse
    {
        $this->mayAnswer($request, $stockCheck);

        $data = $request->validate([
            'counted' => ['required', 'integer', 'min:0'],
            'seen' => ['required', 'integer'],
            'fix' => ['required', 'in:'.StockCheckLine::FIX_ADJUSTED.','.StockCheckLine::FIX_LATER],
            'note' => ['nullable', 'string', 'max:500'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Typed in the reader's currency, like every money box — Section 2b.
        $cost = $request->filled('unit_cost') ? MoneyInput::fromRequest($request, 'unit_cost', $request->user()->lens()) : null;

        return $this->then($stockCheck, $line, fn () => $this->checks->answer(
            $line, (int) $data['seen'], (int) $data['counted'], $data['fix'], $data['note'] ?? null, $cost, $request->user(),
        ), keepCount: (int) $data['counted']);
    }

    public function finish(Request $request, StockCheck $stockCheck): RedirectResponse
    {
        try {
            $this->checks->finish($stockCheck, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', problem($e));
        }

        return redirect()->route('stock-checks.show', $stockCheck)->with('success', __('The stock check is finished.'));
    }

    /** Every wrong answer not yet put right, from every round. */
    public function toFix(Request $request): View
    {
        return view('stock-checks.to-fix', [
            'lines' => StockCheckLine::where('status', StockCheckLine::WRONG)->where('fix', StockCheckLine::FIX_LATER)
                ->with(['product', 'stockCheck', 'checker'])->orderByDesc('checked_at')->paginate(50),
            'canAdjust' => $request->user()->hasPermission('stock_adjustments.create'),
            'mainRoom' => StockRoom::main()->id,
            'lens' => $request->user()->lens(),
        ]);
    }

    public function adjust(Request $request, StockCheckLine $line): RedirectResponse
    {
        $request->validate(['unit_cost' => ['nullable', 'numeric', 'min:0']]);
        $cost = $request->filled('unit_cost') ? MoneyInput::fromRequest($request, 'unit_cost', $request->user()->lens()) : null;

        try {
            $adjustment = $this->checks->adjust($line, $cost, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', problem($e));
        }

        return back()->with('success', __(':number made for :product.', ['number' => $adjustment->document_no, 'product' => $line->product->name]));
    }

    public function fixed(Request $request, StockCheckLine $line): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->checks->markFixed($line, $data['note'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', problem($e));
        }

        return back()->with('success', __(':product is marked fixed.', ['product' => $line->product->name]));
    }

    /** Do it, and go on to the next product — or stay on this one, saying why. */
    private function then(StockCheck $check, StockCheckLine $line, callable $do, ?int $keepCount = null): RedirectResponse
    {
        try {
            $do();
        } catch (RuntimeException $e) {
            return redirect()->route('stock-checks.line', [$check, $line])
                ->with('error', problem($e))
                ->with('counted', $keepCount);
        }

        return redirect()->route('stock-checks.next', $check);
    }

    /** A round's page: whoever may see stock checks, and the person it was sent to. */
    private function maySee(Request $request, StockCheck $check): void
    {
        abort_unless($check->visibleTo($request->user()), 403, __('You do not have permission to do that.'));
    }

    /** ⚠️ A round sent to someone is answered by them alone. */
    private function mayAnswer(Request $request, StockCheck $check): void
    {
        abort_unless($check->answerableBy($request->user()), 403, $check->isSent()
            ? __('This stock check was sent to :name, and only they answer in it.', ['name' => $check->assignee?->name ?? '?'])
            : __('You do not have permission to do that.'));
    }

    /**
     * Who a round can be sent to: active, not the sender, and allowed to
     * count. A handful of people in a shop, so asked one by one.
     *
     * @return Collection<int, User>
     */
    private function counters(User $sender): Collection
    {
        return User::where('is_active', true)->whereKeyNot($sender->id)->with('permissions')->orderBy('name')->get()
            ->filter(fn (User $user) => $user->hasPermission('stock_checks.count'))
            ->values();
    }

    /** @return array<string, mixed> */
    private function report(Request $request, StockCheck $check): array
    {
        $wrong = $check->lines()->where('status', StockCheckLine::WRONG)
            ->with(['product', 'adjustment', 'checker'])->orderBy('position')->get();

        return [
            'check' => $check->load(['category', 'room', 'user']),
            'progress' => $check->progress(),
            'wrong' => $wrong,
            'values' => $wrong->mapWithKeys(fn (StockCheckLine $line) => [$line->id => $this->checks->value($line)]),
            'unchecked' => $check->lines()->whereIn('status', [StockCheckLine::PENDING, StockCheckLine::SKIPPED])
                ->with('product')->orderBy('position')->get(),
            // A blind count answered differently from the first count typed.
            'recounted' => $check->lines()->whereNotNull('first_count')
                ->whereIn('status', [StockCheckLine::CONFIRMED, StockCheckLine::WRONG])
                ->whereColumn('first_count', '!=', 'counted')
                ->with(['product', 'checker'])->orderBy('position')->get(),
            'mayFinish' => ! $check->isFinished() && $check->finishableBy($request->user()),
            'mayAnswer' => ! $check->isFinished() && $check->answerableBy($request->user()),
            'seesCost' => $request->user()->seesRealCost(),
            'lens' => $request->user()->lens(),
        ];
    }
}
