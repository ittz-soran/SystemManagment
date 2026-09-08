<?php

namespace App\Support;

/**
 * The names of the days and months, for the clock in the topbar.
 *
 * The clock is drawn in the browser, from the machine's own time, because it
 * sits beside a real clock on a real wall and has to agree with it. That means
 * the names have to reach the page as data — and they are written here as
 * ordinary `__()` strings rather than pulled from `Intl`, for two reasons.
 *
 * `Intl` has no useful Sorani data in most browsers, and where it has any it
 * gives the Arabic-derived month names used in Iraqi paperwork. Soran asked for
 * the Kurdish ones — سەرماوەز for September — which no library will produce.
 *
 * And going through `__()` means `translations:check` counts them: a month left
 * untranslated fails the build instead of appearing in English on one screen of
 * an otherwise Kurdish shop.
 *
 * The months are the twelve Gregorian months under their Kurdish names, in
 * order. This is not the Kurdish solar calendar — that starts at Newroz and
 * would put a different name against September — it is the naming convention
 * Soran uses, mapped one to one onto the months his customers' invoices carry.
 */
final class CalendarNames
{
    /**
     * Sunday first, matching JavaScript's `Date.getDay()`.
     *
     * @return list<string>
     */
    public static function weekdays(): array
    {
        return [
            __('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'),
            __('Thursday'), __('Friday'), __('Saturday'),
        ];
    }

    /**
     * January first, matching `Date.getMonth()` once one is added to it.
     *
     * @return list<string>
     */
    public static function months(): array
    {
        return [
            __('January'), __('February'), __('March'), __('April'),
            __('May'), __('June'), __('July'), __('August'),
            __('September'), __('October'), __('November'), __('December'),
        ];
    }

    /**
     * Before noon and after it.
     *
     * Written out rather than left as am/pm because Kurdish, Arabic and Persian
     * all say it as words, and "AM" beside a Kurdish date reads like a fault.
     *
     * @return array{0: string, 1: string}
     */
    public static function meridiem(): array
    {
        return [__('am'), __('pm')];
    }

    /**
     * How the parts of a date go together.
     *
     * A format rather than a concatenation, because the order and the joining
     * words differ: Kurdish puts an izafe on the day — ٩ی سەرماوەز — and
     * English wants a comma after the weekday. Assembling it in JavaScript
     * would ship one language's punctuation to all four.
     */
    public static function dateFormat(): string
    {
        return __(':weekday, :day :month :year');
    }

    /** The clock, when it is showing am/pm rather than twenty-four hours. */
    public static function timeFormat(): string
    {
        return __(':time :meridiem');
    }
}
