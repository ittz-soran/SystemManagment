<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The currencies a shop can type and read in — Section 2b.
 *
 * A managed list rather than free text, for the same reason expense categories
 * are: a rate typed fresh on every purchase is a rate nobody can check, and a
 * currency spelled three ways is three currencies.
 *
 * ## ⚠️ Three controls here can change what a stored figure MEANS
 *
 * **`decimals` — what the integer counts.** Changing the base's from 0 to 3 is
 * the redenomination of Section 2b: 250,000 stops reading as 250,000 dinars and
 * starts reading as 250 of them. Nothing is written and nothing migrates, so it
 * is fully reversible — set it back and every screen reads as it did. Allowed,
 * with the warning said out loud on the form.
 *
 * **The base currency itself.** Every stored integer counts base-currency minor
 * units. Point `currency_base` at the dollar and 250,000 recorded dinars become
 * $250,000 — not converted, REINTERPRETED, on every document in the shop. So it
 * moves only while nothing has been recorded: a shop choosing its currency
 * during setup, which is the case that actually needs it. After the first
 * document it is refused, and the screen says why rather than hiding the button.
 *
 * **Deleting one.** Only when nothing points at it. A purchase records the code
 * it was invoiced in, and a code with no row behind it prints as a blank on the
 * invoice that needs it most — so a currency in use is switched off instead,
 * which is what `is_active` is for.
 *
 * **The base currency's rate is never editable.** It is not a number anybody
 * chooses: it is `10^decimals` by definition, and letting somebody set the
 * dinar to 1,320 dinars would break every conversion in the system at once.
 */
class CurrencyController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index(): View
    {
        $currencies = Currency::orderByRaw('code = ? DESC', [Money::base()->code])
            ->orderBy('code')
            ->get();

        return view('currencies.index', [
            'currencies' => $currencies,
            'base' => Money::base(),
            'places' => Currency::RATE_PLACES,

            // Why each currency can or cannot be deleted, and whether the base
            // can move at all. Worked out here so the screen can disable a
            // button and say the reason rather than offering a refusal.
            'blockers' => $currencies->mapWithKeys(
                fn (Currency $c) => [$c->code => $this->whyKept($c)]
            )->all(),
            'recorded' => $this->whatIsRecorded(),
        ]);
    }

    /**
     * Point the books at a different currency — ⚠️ only while they are empty.
     *
     * This does NOT convert anything. Every stored integer counts base-currency
     * minor units, so moving the base re-reads all of them: 250,000 recorded
     * dinars would become $250,000. That is why the guard is "nothing has been
     * recorded" rather than a confirmation dialog — there is no wording that
     * makes reinterpreting a shop's whole ledger a reasonable thing to click.
     */
    public function base(Request $request, Currency $currency): RedirectResponse
    {
        $was = Money::base();

        if ($currency->code === $was->code) {
            return back();
        }

        if ($this->whatIsRecorded() !== null) {
            return back()->with('error', __('The books already have :what recorded in :code. The currency they are kept in cannot change now.', [
                'what' => $this->whatIsRecorded(),
                'code' => $was->code,
            ]));
        }

        DB::transaction(function () use ($currency, $was) {
            /*
             * The old base needs a rate again, and it has never had a real one
             * — a base's rate is 10^decimals by definition. Worked out as the
             * reciprocal so the shop starts from something sensible, switched
             * off so nothing is priced with it until somebody has checked it.
             */
            $was->update([
                'rate' => $this->reciprocal($was, $currency),
                'is_active' => false,
            ]);

            // And the new one takes the definitional rate, always.
            $currency->update([
                'rate' => $currency->minorPerMajor() * Money::RATE_SCALE,
                'is_active' => true,
            ]);

            Setting::put('currency_base', $currency->code);
            Currency::flushCache();
        });

        $this->logger->log(
            action: 'update', module: 'settings',
            description: __('The books moved from :from to :to', ['from' => $was->code, 'to' => $currency->code]),
            user: $request->user(),
        );

        return back()->with('success', __('The books are now kept in :code. Check :old’s rate before offering it again — it was worked out, not typed.', [
            'code' => $currency->code,
            'old' => $was->code,
        ]));
    }

    /** A currency nothing points at can go; anything else is switched off. */
    public function destroy(Request $request, Currency $currency): RedirectResponse
    {
        if ($blocker = $this->whyKept($currency)) {
            return back()->with('error', $blocker);
        }

        $code = $currency->code;
        $currency->delete();

        $this->logger->log(
            action: 'delete', module: 'settings',
            description: __('Removed the currency :code', ['code' => $code]),
            user: $request->user(),
        );

        return back()->with('success', __('Currency removed'));
    }

    public function store(Request $request): RedirectResponse
    {
        $fields = $request->validate([
            // Uppercase and short: ISO 4217 where one exists, because that is
            // what a supplier's invoice and every exchange board already say.
            'code' => ['required', 'string', 'max:8', 'regex:/^[A-Za-z][A-Za-z0-9]*$/', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:8'],
            // Three is the most Money::RATE_PLACES can carry, and no real
            // currency has more than two.
            'decimals' => ['required', 'integer', 'between:0,3'],
            'rate' => ['required', 'string', $this->aRate()],
        ]);

        $currency = Currency::create([
            'code' => strtoupper($fields['code']),
            'name' => $fields['name'],
            'symbol' => ($fields['symbol'] ?? '') ?: null,
            'decimals' => (int) $fields['decimals'],
            'rate' => Currency::scaleRate($fields['rate']),
            'is_active' => true,
        ]);

        $this->logger->log(
            action: 'create', module: 'settings',
            description: __('Added the currency :code', ['code' => $currency->code]),
            user: $request->user(),
        );

        return back()->with('success', __('Currency saved'));
    }

    public function update(Request $request, Currency $currency): RedirectResponse
    {
        $isBase = $currency->code === Money::base()->code;

        $fields = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:8'],
            // `sometimes`, so a caller that does not send the field keeps what
            // the row holds rather than having it wiped to zero.
            'decimals' => ['sometimes', 'required', 'integer', 'between:0,3'],
            'rate' => [Rule::excludeIf($isBase), 'required', 'string', $this->aRate()],
        ]);

        $places = (int) ($fields['decimals'] ?? $currency->decimals);

        $currency->update([
            'name' => $fields['name'],
            'symbol' => ($fields['symbol'] ?? '') ?: null,

            /*
             * ⚠️ On the base this is the redenomination of Section 2b. Nothing
             * is written and no row migrates: the stored integer stops counting
             * dinars and starts counting fils, and only the reading of it
             * changes. Which also makes it reversible — set it back and every
             * screen reads exactly as it did.
             */
            'decimals' => $places,

            /*
             * The base keeps a rate of 10^decimals — it is not a number anybody
             * chooses, and it has to follow the decimals just set rather than
             * the ones the row is still holding. Everything else takes what was
             * typed.
             */
            'rate' => $isBase
                ? (10 ** $places) * Money::RATE_SCALE
                : Currency::scaleRate($fields['rate'] ?? null),

            // ⚠️ The base is always on. A shop cannot switch off the currency
            // its own books are kept in, and the form does not offer it.
            'is_active' => $isBase || $request->boolean('is_active'),
        ]);

        $this->logger->log(
            action: 'update', module: 'settings',
            description: __('Changed the currency :code', ['code' => $currency->code]),
            user: $request->user(),
        );

        return back()->with('success', __('Currency saved'));
    }

    /**
     * Why this currency has to stay, or null when it can go.
     *
     * A message rather than a boolean, because "you cannot delete this" with no
     * reason sends somebody hunting through screens for what is holding it.
     */
    private function whyKept(Currency $currency): ?string
    {
        if ($currency->code === Money::base()->code) {
            return __('The books are kept in :code. Point them at another currency first.', ['code' => $currency->code]);
        }

        $purchases = PurchaseItem::where('entered_currency', $currency->code)->count();

        if ($purchases > 0) {
            return trans_choice(
                '{1}:count purchase line was typed in :code, and its document still names it.'
                .'|[2,*]:count purchase lines were typed in :code, and their documents still name it.',
                $purchases,
                ['count' => number_format($purchases), 'code' => $currency->code],
            );
        }

        $readers = User::where('display_currency', $currency->code)->count();

        if ($readers > 0) {
            return trans_choice(
                '{1}:count person is reading in :code.|[2,*]:count people are reading in :code.',
                $readers,
                ['count' => number_format($readers), 'code' => $currency->code],
            );
        }

        return null;
    }

    /**
     * What the books already hold, or null while nothing has been recorded.
     *
     * ⚠️ Only DOCUMENTS count. A product price is a figure the shopkeeper typed
     * and can retype; a purchase is a thing that happened, and its total is the
     * one number a supplier and a shop have agreed on.
     */
    private function whatIsRecorded(): ?string
    {
        $counts = [
            __('purchases') => Purchase::withoutGlobalScopes()->count(),
            __('sales') => Sale::withoutGlobalScopes()->count(),
            __('payments') => Payment::withoutGlobalScopes()->count(),
            __('expenses') => Expense::withoutGlobalScopes()->count(),
            __('stock adjustments') => StockAdjustment::withoutGlobalScopes()->count(),
        ];

        $found = collect($counts)->filter()->map(
            fn (int $n, string $what) => number_format($n).' '.$what
        );

        return $found->isEmpty() ? null : $found->join(__(', '), __(' and '));
    }

    /**
     * What one unit of the old base is worth in the new one.
     *
     * A starting point, not an answer: it is the reciprocal of a rate somebody
     * typed for the other direction, so it carries that rate's rounding. The
     * old base is switched off alongside it, and the message says to check it.
     */
    private function reciprocal(Currency $was, Currency $now): int
    {
        $perNew = $now->rate;   // old-base minor units per new-base major × 1000

        if ($perNew <= 0) {
            return Money::RATE_SCALE;
        }

        $value = $was->minorPerMajor() * $now->minorPerMajor() * Money::RATE_SCALE * Money::RATE_SCALE / $perNew;

        return max(1, (int) round($value));
    }

    /**
     * A rate a person can type, and that survives being an integer column.
     *
     * Checked with the same reader an amount goes through, so "1,320" and
     * "1320.125" both land and "soon" does not.
     */
    private function aRate(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $scaled = Currency::scaleRate($value);

            if ($scaled === null || $scaled <= 0) {
                $fail(__('The rate must be a number greater than zero.'));
            }
        };
    }
}
