<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The one-pixel scrollbar — Soran's screenshot, 2026-10-30, and *"fix the
 * dashboard and product page scrollbar too"*, 2026-10-31.
 *
 * Rows of fractional height round to a pixel more than their box, and Windows
 * draws a scrollbar with arrows for that pixel. Whether a box rounds over
 * depends on how many rows it has today, so every table box on these pages
 * scrolls sideways only — read from the source, because some of them only
 * appear with some data.
 */
class ScrollbarTest extends TestCase
{
    private const VIEWS = [
        'dashboard' => 2,
        'products/show' => 3,
        'stock-checks/line' => 2,
        'stock-checks/partials/report' => 2,
        'stock-checks/to-fix' => 1,
    ];

    public function test_every_table_box_on_these_pages_scrolls_sideways_only(): void
    {
        foreach (self::VIEWS as $view => $boxes) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));

            preg_match_all('/class="table-responsive[^"]*"/', $source, $found);

            $this->assertCount($boxes, $found[0], "{$view}: a table box was added or taken away — say whether it scrolls");
            $this->assertSame(
                array_fill(0, $boxes, 'class="table-responsive overflow-y-hidden"'),
                $found[0],
                "{$view} has a table box that can scroll up and down",
            );
        }
    }
}
