<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetUserPreferences;
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

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(array_keys(SetUserPreferences::LANGUAGES))],
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
            'items_per_page' => ['required', 'integer', 'min:5', 'max:200'],
        ]);

        $request->user()->forceFill($data)->save();

        return back()->with('success', __('Preferences saved'));
    }
}
