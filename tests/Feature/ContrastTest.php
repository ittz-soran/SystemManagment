<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The brand's colours can be read on the shop's own pages.
 *
 * A colour picked for a filled button, where the words sit on top of it, is a
 * different job from a colour used AS the words — an outline button, an icon, a
 * link. Bootstrap's own grey fails the second job on a dark page: #6c757d on
 * #2b3035 is 2.8:1, which is what "New adjustment" looked like on a dark screen
 * and what this exists to stop coming back.
 *
 * 4.5:1 is WCAG AA for body text. The two surfaces are the least helpful one
 * each theme puts behind a button: dark's tertiary grey, and light's page grey.
 */
class ContrastTest extends TestCase
{
    private const DARK_SURFACE = '#2b3035';

    private const LIGHT_SURFACE = '#f8f9fa';

    /** Every colour a shop might reasonably pick, including the awkward ones. */
    public static function colours(): array
    {
        return [
            'Bootstrap blue' => ['#0d6efd'],
            'Bootstrap grey' => ['#6c757d'],
            'amber' => ['#ffc107'],
            'green' => ['#198754'],
            'near white' => ['#fefefe'],
            'near black' => ['#010101'],
            'hot pink' => ['#ff69b4'],
            'navy' => ['#001f3f'],
        ];
    }

    #[DataProvider('colours')]
    public function test_a_brand_colour_can_be_read_on_either_theme(string $hex): void
    {
        $palette = brand_palette($hex);

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->ratio($palette['on_light'], self::LIGHT_SURFACE),
            "{$hex} is not readable on a light page",
        );

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->ratio($palette['on_dark'], self::DARK_SURFACE),
            "{$hex} is not readable on a dark page",
        );
    }

    /** The variants are the same colour moved, not a different one. */
    public function test_a_colour_that_already_reads_well_is_left_alone(): void
    {
        $palette = brand_palette('#0d6efd');

        $this->assertSame('#0d6efd', $palette['hex']);
        $this->assertNotSame($palette['on_light'], $palette['on_dark']);
    }

    public function test_the_rgb_triplets_match_their_colours(): void
    {
        $palette = brand_palette('#0d6efd');

        foreach ([['on_light', 'on_light_rgb'], ['on_dark', 'on_dark_rgb']] as [$hex, $rgb]) {
            $expected = implode(', ', array_map(hexdec(...), str_split(ltrim($palette[$hex], '#'), 2)));

            $this->assertSame($expected, $palette[$rgb]);
        }
    }

    private function ratio(string $a, string $b): float
    {
        $luminance = function (string $colour): float {
            [$r, $g, $bb] = array_map(hexdec(...), str_split(ltrim($colour, '#'), 2));

            $channel = fn (int $value) => ($v = $value / 255) <= 0.03928
                ? $v / 12.92
                : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($bb);
        };

        $one = $luminance($a);
        $two = $luminance($b);

        return (max($one, $two) + 0.05) / (min($one, $two) + 0.05);
    }
}
