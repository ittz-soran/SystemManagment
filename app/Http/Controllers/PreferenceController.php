<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetUserPreferences;
use App\Models\Currency;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Section 8c layer 3: preferences belong to the person, not the shop.
 *
 * Two people share this system — one prefers Sorani, one English; one works in
 * a bright shop, one at night.
 */
class PreferenceController extends Controller
{
    public function language(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(array_keys(SetUserPreferences::LANGUAGES))],
        ]);

        $request->user()->forceFill($data)->save();

        return back();
    }

    public function theme(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
        ]);

        $request->user()->forceFill($data)->save();

        return back();
    }

    /**
     * Which currency this person reads figures in — Section 2b.
     *
     * Its own action beside language and theme rather than a field on the
     * preferences form, because it is switched mid-task: a reader looks at the
     * report in dollars, then back in dinars, without leaving the page.
     *
     * ⚠️ Blank means the shop's own currency, and so does a code that is not
     * an ACTIVE currency. A rate nobody maintains any more is a rate that
     * quietly goes wrong, so switching one off takes every reader off it.
     */
    public function currency(Request $request): RedirectResponse
    {
        $request->validate([
            'display_currency' => [
                'nullable', 'string', 'max:8',
                Rule::in(Currency::query()->active()->pluck('code')->all()),
            ],
        ]);

        $chosen = (string) $request->input('display_currency');

        $request->user()->forceFill([
            'display_currency' => $chosen === '' || $chosen === Money::base()->code ? null : $chosen,
        ])->save();

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(array_keys(SetUserPreferences::LANGUAGES))],
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
            'items_per_page' => ['required', 'integer', 'min:5', 'max:200'],
            // Two separate questions with separate answers: somebody reading
            // the shop in Sorani may still want dates written the way his
            // supplier writes them, and a twelve-hour clock is a habit rather
            // than a language.
            'date_language' => ['required', Rule::in(['interface', 'english'])],
        ]);

        $data['clock_24_hour'] = $request->boolean('clock_24_hour');

        $request->user()->forceFill($data)->save();

        return back()->with('success', __('Preferences saved'));
    }
}
