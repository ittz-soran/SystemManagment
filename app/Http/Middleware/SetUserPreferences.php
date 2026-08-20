<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 8c layer 3: language, theme and rows-per-page belong to the person,
 * not the shop. They load from the users row already in the session, so this
 * costs no extra query.
 *
 * Section 2: language controls RTL direction, which is why it had to be per user.
 */
class SetUserPreferences
{
    /** Section 2: English is LTR; Sorani, Arabic and Persian are RTL. */
    public const RTL_LANGUAGES = ['ckb', 'ar', 'fa'];

    public const LANGUAGES = [
        'en' => 'English',
        'ckb' => 'کوردیی ناوەندی',
        'ar' => 'العربية',
        'fa' => 'فارسی',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $language = $user?->language
            ?? $request->session()->get('language')
            ?? config('app.locale');

        if (! array_key_exists($language, self::LANGUAGES)) {
            $language = config('app.locale');
        }

        App::setLocale($language);

        // Section 8c: a new user's theme follows the shop's default_theme.
        $theme = $user?->theme ?? setting('default_theme', 'light');

        view()->share([
            'currentLanguage' => $language,
            'isRtl' => in_array($language, self::RTL_LANGUAGES, true),
            'currentTheme' => $theme,
            'perPage' => $user?->items_per_page ?? 25,
        ]);

        return $next($request);
    }
}
