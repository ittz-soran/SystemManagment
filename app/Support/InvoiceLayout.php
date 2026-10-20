<?php

namespace App\Support;

/**
 * Where each layer of the A5 government invoice sits on the shop's paper —
 * PROJECT_DOC Section 9, the invoice designer. Soran, 2026-10-20: *"should
 * layers are changeable and sizes with fonts"*.
 *
 * Every number is millimetres from the top left of the A5 sheet, or points
 * for a font size. The starting places are his book's, measured off the PDF he
 * sent, so a shop that never opens the designer prints exactly what it did.
 *
 * Kept as one JSON setting. A layer the setting does not mention takes its
 * starting place, so adding a layer later needs no data step; a key the
 * setting holds that is no longer a layer is dropped on the way past.
 */
final class InvoiceLayout
{
    public const SETTING = 'invoice_a5_layout';

    /** The A5 sheet. */
    public const WIDTH = 148.0;

    public const HEIGHT = 210.0;

    /** The smallest a box can be made, either way. */
    public const SMALLEST = 3.0;

    /** Font sizes, in points. 6 is also the floor a long line shrinks to. */
    public const SIZE_MIN = 6;

    public const SIZE_MAX = 28;

    /** Row height of the item lines, and the narrowest a column can be. */
    public const ROW_MIN = 4.0;

    public const ROW_MAX = 30.0;

    public const COLUMN_MIN = 5.0;

    /**
     * His book. `columns` are the three dividers of the item lines — between
     * Price, Qty, Content and Amount — as distances from the box's left edge.
     */
    public const DEFAULTS = [
        'customer' => ['x' => 21.0, 'y' => 54.5, 'w' => 103.7, 'h' => 5.4, 'size' => 10, 'bold' => false, 'show' => true],
        'date' => ['x' => 15.0, 'y' => 64.7, 'w' => 25.4, 'h' => 5.4, 'size' => 10, 'bold' => false, 'show' => true],
        'address' => ['x' => 84.2, 'y' => 64.7, 'w' => 32.4, 'h' => 5.4, 'size' => 10, 'bold' => false, 'show' => true],
        'lines' => [
            'x' => 2.4, 'y' => 81.3, 'w' => 143.1, 'h' => 90.2, 'size' => 10, 'bold' => false, 'show' => true,
            'row' => 8.2, 'columns' => [25.7, 39.5, 116.1],
        ],
        'words' => ['x' => 4.0, 'y' => 172.0, 'w' => 90.0, 'h' => 7.9, 'size' => 10, 'bold' => true, 'show' => true],
        'total' => ['x' => 118.8, 'y' => 172.0, 'w' => 26.5, 'h' => 7.9, 'size' => 10, 'bold' => true, 'show' => true],
        'number' => ['x' => 17.5, 'y' => 196.4, 'w' => 20.5, 'h' => 5.4, 'size' => 9, 'bold' => true, 'show' => true],
    ];

    /** The item lines are the invoice; the rest a paper may not have room for. */
    public const ALWAYS_SHOWN = ['lines'];

    /**
     * The layout the shop has, every layer present.
     *
     * A setting that cannot be read — hand-edited, cut short — is the book's
     * places rather than an invoice that will not print.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function current(): array
    {
        $saved = json_decode((string) setting(self::SETTING, ''), true);

        if (! is_array($saved)) {
            return self::DEFAULTS;
        }

        $layout = [];

        foreach (self::DEFAULTS as $key => $default) {
            $layer = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $layout[$key] = self::problems($key, $layer) === []
                ? self::normalise($key, $layer)
                : $default;
        }

        return $layout;
    }

    /**
     * What is wrong with a submitted layout, as messages to show; none when it
     * can be kept.
     *
     * @return list<string>
     */
    public static function check(mixed $layout): array
    {
        if (! is_array($layout)) {
            return [__('The layout could not be read. Reload the designer and try again.')];
        }

        $problems = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $layer = $layout[$key] ?? null;

            if (! is_array($layer)) {
                $problems[] = __('The layout is missing :layer.', ['layer' => self::name($key)]);

                continue;
            }

            foreach (self::problems($key, $layer) as $problem) {
                $problems[] = self::name($key).': '.$problem;
            }
        }

        return $problems;
    }

    /**
     * A checked layout as it is kept: only the known layers and their known
     * fields, numbers rounded to a tenth of a millimetre.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, array<string, mixed>>
     */
    public static function clean(array $layout): array
    {
        $clean = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $clean[$key] = self::normalise($key, $layout[$key]);
        }

        return $clean;
    }

    /** A layer's name, on the screen's language. */
    public static function name(string $key): string
    {
        return match ($key) {
            'customer' => __('Customer'),
            'date' => __('Date'),
            'address' => __('Address'),
            'lines' => __('Item lines'),
            'words' => __('Total in words'),
            'total' => __('Total'),
            'number' => __('Invoice number'),
            default => $key,
        };
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return list<string>
     */
    private static function problems(string $key, array $layer): array
    {
        $problems = [];

        foreach (['x', 'y', 'w', 'h'] as $field) {
            if (! self::isNumber($layer[$field] ?? null)) {
                return [__('its place is not a number.')];
            }
        }

        [$x, $y, $w, $h] = [(float) $layer['x'], (float) $layer['y'], (float) $layer['w'], (float) $layer['h']];

        // A tenth of a millimetre of slack: the designer rounds as it drags.
        if ($x < 0 || $y < 0 || $x + $w > self::WIDTH + 0.1 || $y + $h > self::HEIGHT + 0.1) {
            $problems[] = __('it goes off the sheet.');
        }

        if ($w < self::SMALLEST || $h < self::SMALLEST) {
            $problems[] = __('it is smaller than :mm mm.', ['mm' => self::SMALLEST]);
        }

        $size = $layer['size'] ?? null;
        if (! self::isNumber($size) || $size < self::SIZE_MIN || $size > self::SIZE_MAX) {
            $problems[] = __('the font size must be between :min and :max.', ['min' => self::SIZE_MIN, 'max' => self::SIZE_MAX]);
        }

        if ($key !== 'lines') {
            return $problems;
        }

        $row = $layer['row'] ?? null;
        if (! self::isNumber($row) || $row < self::ROW_MIN || $row > self::ROW_MAX) {
            $problems[] = __('the row height must be between :min and :max mm.', ['min' => self::ROW_MIN, 'max' => self::ROW_MAX]);
        }

        $columns = $layer['columns'] ?? null;
        if (! is_array($columns) || count($columns) !== 3 || ! array_is_list($columns)
            || array_filter($columns, fn ($c) => ! self::isNumber($c)) !== []) {
            return [...$problems, __('the columns could not be read.')];
        }

        // Each column at least COLUMN_MIN wide, the last one included.
        $edges = [0.0, ...array_map('floatval', $columns), $w];
        $narrowest = min(array_map(fn ($i) => $edges[$i] - $edges[$i - 1], range(1, count($edges) - 1)));
        if ($narrowest < self::COLUMN_MIN - 0.05) {
            $problems[] = __('every column must be at least :mm mm wide.', ['mm' => self::COLUMN_MIN]);
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    private static function normalise(string $key, array $layer): array
    {
        $tenth = fn ($n) => round((float) $n, 1);

        $clean = [
            'x' => $tenth($layer['x']),
            'y' => $tenth($layer['y']),
            'w' => $tenth($layer['w']),
            'h' => $tenth($layer['h']),
            'size' => round((float) $layer['size'] * 2) / 2,
            'bold' => filter_var($layer['bold'] ?? false, FILTER_VALIDATE_BOOL),
            'show' => in_array($key, self::ALWAYS_SHOWN, true) || filter_var($layer['show'] ?? true, FILTER_VALIDATE_BOOL),
        ];

        if ($key === 'lines') {
            $clean['row'] = $tenth($layer['row']);
            $clean['columns'] = array_map($tenth, $layer['columns']);
        }

        return $clean;
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }
}
