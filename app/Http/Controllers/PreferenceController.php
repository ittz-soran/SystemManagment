<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetUserPreferences;
use App\Models\Currency;
use App\Support\Adhkar;
use App\Support\Money;
use App\Support\Notifications;
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

    /**
     * Which tiers of notification reach this person.
     *
     * ⚠️ Alerts are not on this form and cannot be turned off. Somebody who has
     * silenced everything should still be told that their own account was
     * signed into from an address they do not use, and that the invoices were
     * deleted. A preference here is about noise, not about being kept in the
     * dark — `Notifications::tiersFor()` puts alerts back whatever is stored.
     */
    public function notifications(Request $request): RedirectResponse
    {
        $wanted = collect(Notifications::TIERS)
            ->filter(fn (string $tier) => $tier === Notifications::ALERT || $request->boolean($tier))
            ->values();

        $request->user()->forceFill(['notify_tiers' => $wanted->implode(',')])->save();

        return back()->with('success', __('Preferences saved'));
    }

    /**
     * Whether the remembrances appear for this person.
     *
     * ⚠️ Per person, not per shop. What somebody says at their own counter is
     * not an admin's setting to make on their behalf — so this sits beside
     * language and theme rather than in Settings, and an admin turning it off
     * turns it off for the admin.
     */
    public function remembrance(Request $request): RedirectResponse
    {
        $request->validate([
            // ⚠️ In the list or nowhere. A free number would let somebody ask
            // for one every six seconds, which is a screen nobody can work at.
            'adhkar_every' => ['nullable', Rule::in(Adhkar::EVERY)],
        ]);

        $user = $request->user();
        $changes = ['adhkar_off' => ! $request->boolean('adhkar')];

        // Absent means "this form did not ask" — the switch on the remembrance
        // page posts without it, and must not silently reset how often they
        // appear.
        if ($request->has('adhkar_every')) {
            $changes['adhkar_every'] = (int) $request->input('adhkar_every');
        }

        $user->forceFill($changes)->save();

        return back()->with('success', __('Preferences saved'));
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
