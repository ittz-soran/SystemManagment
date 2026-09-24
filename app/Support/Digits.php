<?php

namespace App\Support;

/**
 * Numbers as the computer reads them, whatever keyboard typed them.
 *
 * Three of this shop's four languages are written with their own digits, so a
 * keyboard left on Kurdish, Arabic or Persian types ٤٥٠٠ where a price wants
 * 4500 — and a barcode scanner is a keyboard, so it does it too. app.js already
 * translates the digits as they land in a number field; this is the same rule
 * for text that reaches the server, where a search box takes ٠٧٥٠ for a phone
 * number and INV-٠٠٠٠٥ for an invoice.
 *
 * ⚠️ Digits only. The letters on those layouts are a different problem, and not
 * one either side can solve: a shopkeeper typing a product name in Kurdish
 * means the Kurdish name.
 */
final class Digits
{
    /** Arabic-Indic ٠١٢٣ and the extended Persian and Urdu ۰۱۲۳, as 0123. */
    private const EASTERN = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * ٤٥٠٠ → 4500, ۴۵۰۰ → 4500, ٤٫٥ → 4.5, ١٬٠٠٠ → 1000.
     *
     * The Arabic decimal mark becomes a point and the Arabic thousands mark is
     * dropped, exactly the way a typed comma would be — word for word what
     * app.js does in the browser, so a figure means the same thing whichever
     * side of the wire it is read on.
     */
    public static function english(string $value): string
    {
        return strtr($value, self::EASTERN + ['٫' => '.', '٬' => '']);
    }
}
