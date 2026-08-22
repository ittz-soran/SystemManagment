<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Label stock
    |---------------------------------------------------------------------------
    |
    | The sizes a roll of labels actually comes in. Each is a page in its own
    | right: the printer advances one label per page, so a run of ten copies is
    | ten pages of exactly these dimensions.
    |
    | `barcode` is the height of the bars in millimetres; the rest is type size
    | in points. A narrower label needs smaller type and shorter bars, which is
    | why these are per-profile rather than one set of numbers.
    |
    */

    'sizes' => [
        '50x30' => ['label' => 'Roll · 50 × 30 mm', 'width' => 50, 'height' => 30, 'padding' => 2,
            'barcode' => 11, 'name' => 7, 'price' => 9, 'small' => 6],

        '40x30' => ['label' => 'Roll · 40 × 30 mm', 'width' => 40, 'height' => 30, 'padding' => 1.5,
            'barcode' => 10, 'name' => 6.5, 'price' => 8, 'small' => 5.5],

        '50x25' => ['label' => 'Roll · 50 × 25 mm', 'width' => 50, 'height' => 25, 'padding' => 1.5,
            'barcode' => 9, 'name' => 6.5, 'price' => 8, 'small' => 5.5],

        '60x40' => ['label' => 'Roll · 60 × 40 mm', 'width' => 60, 'height' => 40, 'padding' => 2.5,
            'barcode' => 14, 'name' => 8, 'price' => 11, 'small' => 7],

        '30x20' => ['label' => 'Roll · 30 × 20 mm', 'width' => 30, 'height' => 20, 'padding' => 1,
            'barcode' => 8, 'name' => 5.5, 'price' => 6.5, 'small' => 5],
    ],

    /*
    |---------------------------------------------------------------------------
    | The narrowest an EAN-13 stays scannable
    |---------------------------------------------------------------------------
    |
    | An EAN-13 is 95 modules wide. Squeezed below about 30 mm the bars get thin
    | enough that a cheap scanner starts missing them, so the label screen warns
    | rather than printing something that will not read at the till.
    |
    */

    'min_barcode_width' => 30,

    /*
    |---------------------------------------------------------------------------
    | Direct printing
    |---------------------------------------------------------------------------
    |
    | XPrinter label printers speak TSPL, so the server can send the commands
    | straight to a shared printer and skip the browser's print dialog. Only
    | possible because the application runs on the same machine as the printer.
    |
    | Left unset, the browser's print dialog is the only route — which always
    | works and needs no setup at all.
    |
    */

    'printer' => env('LABEL_PRINTER'),

    'timeout' => (int) env('LABEL_PRINT_TIMEOUT', 20),

];
