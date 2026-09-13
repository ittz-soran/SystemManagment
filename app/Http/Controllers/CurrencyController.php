<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Services\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The currencies a shop can type and read in — Section 2b.
 *
 * A managed list rather than free text, for the same reason expense categories
 * are: a rate typed fresh on every purchase is a rate nobody can check, and a
 * currency spelled three ways is three currencies.
 *
 * ⚠️ **Two things are deliberately not editable here, and both are refusals
 * rather than omissions.**
 *
 * **The base currency's `decimals`.** That field is what every stored integer
 * counts — changing it from 0 to 3 IS the redenomination, and it reprices every
 * screen in the shop. Section 2b holds it until the entry half lands, because a
 * shop that changed it today could READ 15.5 and not be able to TYPE it.
 *
 * **The base currency's rate.** It is not a rate anybody chooses; it is
 * `10^decimals` by definition, and letting somebody set the dinar to 1,320
 * dinars would break every conversion in the system at once.
 *
 * Currencies are switched off, never deleted. A shop that stops buying in
 * dollars still has purchases whose recorded rate says USD, and a code with no
 * row behind it prints as a blank on the invoice that needs it most.
 */
class CurrencyController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index(): View
    {
        return view('currencies.index', [
            'currencies' => Currency::orderByRaw('code = ? DESC', [Money::base()->code])
                ->orderBy('code')
                ->get(),
            'base' => Money::base(),
            'places' => Currency::RATE_PLACES,
        ]);
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
            'rate' => [Rule::excludeIf($isBase), 'required', 'string', $this->aRate()],
        ]);

        $currency->update([
            'name' => $fields['name'],
            'symbol' => ($fields['symbol'] ?? '') ?: null,

            /*
             * The base keeps a rate of 10^decimals — it is not a number anybody
             * chooses. Everything else takes what was typed.
             */
            'rate' => $isBase
                ? $currency->minorPerMajor() * Money::RATE_SCALE
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
