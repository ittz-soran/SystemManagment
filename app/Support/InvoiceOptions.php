<?php

namespace App\Support;

/**
 * What the A5 government invoice says, and how — PROJECT_DOC Section 9.
 * Soran, 2026-10-20: *"set numbers language -> have curency Symbol or not ->
 * … INV-009 show as 009 or not -> if one of Layers not text show as " - " or
 * empty " " -> in kurdish 1 psc is "١ دانە" or just show " ١ ""*.
 *
 * The starting value of each is what the invoice printed before there was a
 * choice, so nothing changes until a shop changes it. Kept as one JSON
 * setting; anything it does not hold takes its starting value.
 */
final class InvoiceOptions
{
    public const SETTING = 'invoice_a5_options';

    public const DEFAULTS = [
        // latin 123 · arabic ١٢٣ (Kurdish and Arabic) · persian ۱۲۳
        'digits' => 'latin',
        // The currency's mark after the total.
        'currency' => true,
        // full INV-00009 · number 00009
        'number' => 'full',
        // What a layer with nothing to say prints: nothing, or a dash.
        'empty' => 'blank',
        // The quantity with its unit, or the number alone.
        'unit' => true,
        // The invoice's own word for a unit: pcs → دانە. Missing is as typed.
        'units' => [],
    ];

    public const DIGITS = ['latin', 'arabic', 'persian'];

    public const NUMBER = ['full', 'number'];

    public const EMPTY = ['blank', 'dash'];

    /** Longest unit, and its word, a shop is likely to type. */
    private const UNIT_LENGTH = 30;

    /** @return array<string, mixed> */
    public static function current(): array
    {
        $saved = json_decode((string) setting(self::SETTING, ''), true);

        return self::check($saved) === [] ? self::clean($saved) : self::DEFAULTS;
    }

    /**
     * What is wrong with submitted options, as messages to show.
     *
     * @return list<string>
     */
    public static function check(mixed $options): array
    {
        if (! is_array($options)) {
            return [__('The invoice options could not be read. Reload the designer and try again.')];
        }

        $problems = [];
        $options += self::DEFAULTS;

        if (! in_array($options['digits'], self::DIGITS, true)
            || ! in_array($options['number'], self::NUMBER, true)
            || ! in_array($options['empty'], self::EMPTY, true)
            || ! is_bool($options['currency']) || ! is_bool($options['unit'])) {
            $problems[] = __('The invoice options could not be read. Reload the designer and try again.');
        }

        $units = $options['units'];
        if (! is_array($units) || count($units) > 100) {
            $problems[] = __('The unit words could not be read.');
        } else {
            foreach ($units as $unit => $word) {
                if (! is_string($word) || mb_strlen((string) $unit) > self::UNIT_LENGTH || mb_strlen($word) > self::UNIT_LENGTH) {
                    $problems[] = __('A unit\'s word on the invoice can be at most :n letters.', ['n' => self::UNIT_LENGTH]);
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * Checked options as they are kept: only the known ones, and only the unit
     * words that say something.
     *
     * ⚠️ A unit is a string even when it is all digits: PHP makes the array key
     * '10' the integer 10, so every key is cast back.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function clean(array $options): array
    {
        $options += self::DEFAULTS;

        $units = [];
        foreach ($options['units'] as $unit => $word) {
            $unit = trim((string) $unit);
            $word = trim($word);
            if ($unit !== '' && $word !== '') {
                $units[$unit] = $word;
            }
        }

        return [
            'digits' => $options['digits'],
            'currency' => $options['currency'],
            'number' => $options['number'],
            'empty' => $options['empty'],
            'unit' => $options['unit'],
            'units' => $units,
        ];
    }
}
