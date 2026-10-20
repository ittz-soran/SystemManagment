<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A font file the invoice can print in — PROJECT_DOC Section 9, the invoice
 * designer: Soran, 2026-10-20, *"i want use specific font"*.
 *
 * Read by its first four bytes, not by what the browser calls it. A .ttf comes
 * up as font/ttf, font/sfnt, application/x-font-ttf or plain
 * application/octet-stream depending on the machine, so a type check would
 * refuse good files on one computer and pass anything named .ttf on another.
 * The signature is what every font reader looks at first.
 */
class FontFile implements ValidationRule
{
    /** What each kind of font file starts with, and the name it is kept under. */
    public const SIGNATURES = [
        "\x00\x01\x00\x00" => 'ttf',
        'true' => 'ttf',
        'OTTO' => 'otf',
        'wOFF' => 'woff',
        'wOF2' => 'woff2',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || self::kind($value) === null) {
            $fail(__('That is not a font file. Choose a .ttf, .otf, .woff or .woff2 file.'));
        }
    }

    /** ttf, otf, woff or woff2 — or null for anything that is not a font. */
    public static function kind(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        $start = $path === false ? false : @file_get_contents($path, false, null, 0, 4);

        return is_string($start) ? (self::SIGNATURES[$start] ?? null) : null;
    }
}
