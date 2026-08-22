<?php

namespace App\Services;

use App\Models\Product;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;
use RuntimeException;

/**
 * Shelf labels for products whose barcode the system generated.
 *
 * Section 4 gives auto-generated barcodes an internal-use EAN-13 prefix so they
 * never collide with a manufacturer's. The flip side is that nothing is printed
 * on the goods, so the shop has to make the label itself — which is what this
 * is for.
 *
 * One label spec drives two renderers: HTML for the browser's print dialog, and
 * TSPL for a printer the server can reach directly. Both must produce the same
 * label, so everything that decides what a label looks like is settled here and
 * neither renderer gets to make its own choices.
 */
class LabelService
{
    /** What a label may show. Everything is optional except the barcode itself. */
    public const FIELDS = ['shop', 'name', 'sku', 'price', 'barcode_number'];

    /**
     * The label as a plain description, before either renderer touches it.
     *
     * @param  array<string, bool>  $fields
     * @return array{product: Product, code: string, size: array<string, mixed>, sizeKey: string,
     *               copies: int, fields: array<string, bool>, warning: string|null}
     */
    public function spec(Product $product, ?string $sizeKey = null, array $fields = [], int $copies = 1): array
    {
        if (blank($product->barcode)) {
            throw new RuntimeException(__('This product has no barcode yet, so there is nothing to print.'));
        }

        $sizeKey ??= (string) setting('label_size', array_key_first(config('labels.sizes')));
        $size = config('labels.sizes.'.$sizeKey)
            ?? throw new RuntimeException(__('Unknown label size: :size', ['size' => $sizeKey]));

        return [
            'product' => $product,
            'code' => (string) $product->barcode,
            'size' => $size,
            'sizeKey' => $sizeKey,
            'copies' => max(1, min(500, $copies)),
            'fields' => $this->fields($fields),
            'warning' => $this->warning($size),
        ];
    }

    /**
     * Which fields to show, falling back to the shop's saved defaults.
     *
     * @param  array<string, bool>  $chosen
     * @return array<string, bool>
     */
    public function fields(array $chosen = []): array
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            $fields[$field] = array_key_exists($field, $chosen)
                ? (bool) $chosen[$field]
                : (bool) setting('label_show_'.$field, $field !== 'shop');
        }

        return $fields;
    }

    /**
     * How wide the bars themselves may be, given the room the label has.
     *
     * Not all of it: a barcode needs a quiet zone — plain background either
     * side — or a scanner cannot tell where the code begins. The standard asks
     * for 11 modules before an EAN-13 and 7 after, against 95 modules of bars,
     * so the bars get 95/113 of the space and the rest is margin. Bars printed
     * edge to edge look tidier and read far worse.
     */
    public function barcodeWidth(string $code, array $size): float
    {
        $usable = $size['width'] - 2 * $size['padding'];

        $ratio = preg_match('/^\d{13}$/', $code) === 1
            ? 95 / 113
            // Code 128 asks for 10 modules either side; its own width varies
            // with the data, so this is the same idea at a safe approximation.
            : 0.86;

        return round($usable * $ratio, 2);
    }

    /**
     * The barcode as an SVG, sized in millimetres so it prints at the real size
     * rather than whatever a screen's pixel density suggests.
     */
    public function svg(string $code, float $widthMm, float $heightMm): string
    {
        $barcode = $this->barcode($code);

        // The renderer works in its own units; the SVG is then scaled to the
        // millimetres the label allows.
        $svg = (new SvgRenderer())->render($barcode, $barcode->getWidth() * 2, 100);

        return preg_replace(
            '/<svg width="[^"]*" height="[^"]*"/',
            sprintf('<svg width="%smm" height="%smm" preserveAspectRatio="none"', $widthMm, $heightMm),
            $svg,
            1,
        );
    }

    /**
     * EAN-13 where the code is one, Code 128 otherwise.
     *
     * Section 4 generates EAN-13, but a manufacturer's code that was typed in
     * by hand may be anything, and refusing to print a label for it would be
     * unhelpful.
     */
    private function barcode(string $code)
    {
        $isEan13 = preg_match('/^\d{13}$/', $code) === 1;

        return $isEan13
            ? (new TypeEan13())->getBarcode($code)
            : (new TypeCode128())->getBarcode($code);
    }

    /**
     * TSPL, which is what an XPrinter label printer speaks.
     *
     * The printer draws the barcode itself from the digits, so this is sharper
     * than sending it an image — and far smaller down the wire.
     *
     * @param  array<string, mixed>  $spec
     */
    public function tspl(array $spec): string
    {
        $size = $spec['size'];
        $fields = $spec['fields'];
        $product = $spec['product'];

        // TSPL works in dots. 8 dots per mm is 203 dpi, which is what these
        // printers are.
        $dots = fn (float $mm) => (int) round($mm * 8);

        $pad = $dots($size['padding']);
        $width = $dots($size['width']);
        $lines = [];

        $lines[] = sprintf('SIZE %s mm,%s mm', $size['width'], $size['height']);
        $lines[] = 'GAP 2 mm,0 mm';
        $lines[] = 'DIRECTION 1';
        $lines[] = 'CLS';

        $y = $pad;

        if ($fields['shop']) {
            $lines[] = sprintf('TEXT %d,%d,"1",0,1,1,"%s"', $pad, $y, $this->escape(setting('shop_name', '')));
            $y += $dots(3);
        }

        if ($fields['name']) {
            $lines[] = sprintf('TEXT %d,%d,"2",0,1,1,"%s"', $pad, $y, $this->escape($this->trim($product->name, $size)));
            $y += $dots(4);
        }

        // The barcode gets whatever vertical room is left after the text, and
        // the printer prints the digits under it when the last argument is 2.
        $barcodeHeight = $dots($size['barcode']);
        $human = $fields['barcode_number'] ? 2 : 0;

        $lines[] = sprintf(
            'BARCODE %d,%d,"%s",%d,%d,0,2,2,"%s"',
            $pad,
            $y,
            preg_match('/^\d{13}$/', $spec['code']) ? 'EAN13' : '128',
            $barcodeHeight,
            $human,
            $spec['code'],
        );

        $y += $barcodeHeight + $dots($human ? 4 : 1);

        if ($fields['sku'] || $fields['price']) {
            if ($fields['sku']) {
                $lines[] = sprintf('TEXT %d,%d,"1",0,1,1,"%s"', $pad, $y, $this->escape($product->sku));
            }

            if ($fields['price']) {
                // Right-aligned: alignment 3 in TSPL, anchored at the far edge.
                $lines[] = sprintf(
                    'TEXT %d,%d,"2",0,1,1,3,"%s"',
                    $width - $pad,
                    $y,
                    $this->escape(money($product->sale_price)),
                );
            }
        }

        $lines[] = sprintf('PRINT %d,1', $spec['copies']);

        return implode("\r\n", $lines)."\r\n";
    }

    /** A name longer than the label is worse than a shortened one. */
    public function trim(string $name, array $size): string
    {
        // Roughly two millimetres per character at the name's type size.
        $limit = (int) max(8, ($size['width'] - 2 * $size['padding']) / ($size['name'] * 0.28));

        return mb_strlen($name) > $limit ? mb_substr($name, 0, $limit - 1).'…' : $name;
    }

    /**
     * Section 9b: warn, do not block. A 30 mm label is legitimate for small
     * items; it just cannot carry a full EAN-13 that a till scanner will read.
     */
    private function warning(array $size): ?string
    {
        $usable = $size['width'] - 2 * $size['padding'];

        if ($usable >= config('labels.min_barcode_width', 30)) {
            return null;
        }

        return __('At :width mm the bars get thin enough that a cheap scanner may miss them. Test one before printing a roll.', [
            'width' => round($usable, 1),
        ]);
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }
}
