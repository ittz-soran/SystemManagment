<?php

namespace App\Support;

use App\Models\Repair;
use App\Models\StockBatch;
use App\Models\StockRoom;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The sections a shop can do without — PROJECT_DOC Section 8h, Soran,
 * 2026-10-19: *"one of stores not need all or fully system sections"*.
 *
 * Switched by two lines in the shop's .env, which Soran Panel writes. Nothing
 * here ever deletes a row or moves a figure: a section that is off is screens
 * nobody can open, and a section turned back on is exactly what it was.
 *
 * The shape of a section:
 *
 *   name         what the panel calls it — English, the panel has one reader
 *   permissions  the permission group whose keys it takes away, or null
 *   paths        every address that belongs to it, in Request::is() patterns
 *   modules      the activity-log modules whose news it takes off the bell
 *   testing      true while it is being tried on some shops before all of them
 *
 * ⚠️ **PATHS, NOT ONLY PERMISSIONS.** Buying second-hand opens with
 * `purchases.create` and the currency page with `settings.manage`, so a
 * permission check alone would leave both answering. SectionsTest fails if a
 * route guarded by a section's permission is missing from that section's paths.
 */
final class Sections
{
    public const LIST = [
        'stock_rooms' => [
            'name' => 'Stock rooms',
            'permissions' => 'stock_rooms',
            'paths' => ['stock-rooms', 'stock-rooms/*', 'stock-transfers', 'stock-transfers/*'],
            'modules' => ['stock_rooms', 'stock_transfers'],
            'testing' => false,
        ],
        'second_hand' => [
            'name' => 'Second-hand',
            'permissions' => 'second_hand',
            'paths' => ['second-hand', 'second-hand/*'],
            'modules' => ['second_hand'],
            'testing' => false,
        ],
        // The workshop only. Services — a price line on a sale — stay on.
        'repairs' => [
            'name' => 'Repairs',
            'permissions' => 'repairs',
            'paths' => ['repairs', 'repairs/*', 'reports/technicians'],
            'modules' => ['repairs'],
            'testing' => false,
        ],
        'assemblies' => [
            'name' => 'Take apart & build',
            'permissions' => 'assemblies',
            'paths' => ['assemblies', 'assemblies/*'],
            'modules' => ['assemblies'],
            'testing' => false,
        ],
        'swaps' => [
            'name' => 'Swaps',
            'permissions' => 'swaps',
            'paths' => ['swaps', 'swaps/*', 'goods-back/swap'],
            'modules' => ['swaps'],
            'testing' => false,
        ],
        // No permission of its own — its page sits under Settings. What the
        // screens offer goes through Currency::offered() and User::lens().
        'currencies' => [
            'name' => 'Currencies',
            'permissions' => null,
            'paths' => ['settings/currencies', 'settings/currencies/*', 'preferences/currency'],
            'modules' => ['currencies'],
            'testing' => false,
        ],
    ];

    /** @return array<string, array{name: string, permissions: ?string, paths: list<string>, modules: list<string>, testing: bool}> */
    public static function all(): array
    {
        return config('sections.list') ?? self::LIST;
    }

    /**
     * Whether this shop has the section.
     *
     * ⚠️ OFF wins. A name in both lines is off: switching something off is the
     * deliberate act, and a shop that loses a page it was meant to keep says
     * so at once, where one that keeps a page it was meant to lose never does.
     */
    public static function isOn(string $key): bool
    {
        $section = self::all()[$key] ?? throw new InvalidArgumentException("No section is called [{$key}].");

        if (in_array($key, self::named('off'), true)) {
            return false;
        }

        return ! $section['testing'] || in_array($key, self::named('on'), true);
    }

    /** @return list<string> */
    public static function off(): array
    {
        return array_values(array_filter(array_keys(self::all()), fn (string $key) => ! self::isOn($key)));
    }

    /** Where the answer came from: the section's own default, or one of the two lines. */
    public static function why(string $key): string
    {
        $testing = self::all()[$key]['testing'];

        return match (true) {
            in_array($key, self::named('off'), true) => 'SHOP_SECTIONS_OFF',
            $testing && in_array($key, self::named('on'), true) => 'SHOP_SECTIONS_ON',
            default => 'default',
        };
    }

    /**
     * Names in either line that are no section at all.
     *
     * ⚠️ `SHOP_SECTIONS_OFF=repair` switches nothing off, and a shop that was
     * meant to lose a section and did not is a fault nobody notices. So these
     * are reported, never quietly dropped.
     *
     * @return list<string>
     */
    public static function unknown(): array
    {
        return array_values(array_diff(
            array_unique([...self::named('off'), ...self::named('on')]),
            array_keys(self::all()),
        ));
    }

    /** False when the permission belongs to a section this shop has not got. */
    public static function allowsPermission(string $permission): bool
    {
        $group = Str::before($permission, '.');

        foreach (self::off() as $key) {
            if (self::all()[$key]['permissions'] === $group) {
                return false;
            }
        }

        return true;
    }

    /** False when the bell's news from this module belongs to a section that is off. */
    public static function allowsModule(string $module): bool
    {
        foreach (self::off() as $key) {
            if (in_array($module, self::all()[$key]['modules'], true)) {
                return false;
            }
        }

        return true;
    }

    /** The section that is off and owns this address, if any. */
    public static function offFor(Request $request): ?string
    {
        foreach (self::off() as $key) {
            if ($request->is(...self::all()[$key]['paths'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * What stops the section being switched off, in a sentence for the panel.
     *
     * Asked of a section whether it is on or off, so the panel can show it
     * either way. Null means nothing holds it.
     */
    public static function holds(string $key): ?string
    {
        return match ($key) {
            'stock_rooms' => self::stockOutsideMainRoom(),
            'repairs' => self::openRepairs(),
            default => null,
        };
    }

    /**
     * The whole answer, for `shop:sections --json` and `shop:doctor` — one
     * implementation, so the two can never disagree.
     *
     * ⚠️ "Could not ask" is not "nothing holds it". What holds a section is
     * read from the database; if that does not answer, `holds_checked` is false
     * and nothing may be switched off on the strength of an empty answer.
     *
     * @return array{sections: list<array{key: string, name: string, on: bool, why: string, testing: bool, holds: ?string}>, unknown: list<string>, holds_checked: bool}
     */
    public static function report(): array
    {
        $checked = true;
        $sections = [];

        foreach (self::all() as $key => $section) {
            try {
                $holds = self::holds($key);
            } catch (Throwable) {
                $holds = null;
                $checked = false;
            }

            $sections[] = [
                'key' => $key,
                'name' => $section['name'],
                'on' => self::isOn($key),
                'why' => self::why($key),
                'testing' => $section['testing'],
                'holds' => $holds,
            ];
        }

        return ['sections' => $sections, 'unknown' => self::unknown(), 'holds_checked' => $checked];
    }

    /** @return list<string> */
    private static function named(string $line): array
    {
        $names = array_map(
            fn (string $name) => Str::lower(trim($name)),
            explode(',', (string) config('sections.'.$line, '')),
        );

        return array_values(array_unique(array_filter($names, fn (string $name) => $name !== '')));
    }

    /**
     * ⚠️ The till sells only from the main room, so stock anywhere else is
     * stock nobody could reach once the moves screen is gone.
     */
    private static function stockOutsideMainRoom(): ?string
    {
        $main = StockRoom::main()->id;

        $elsewhere = StockBatch::query()
            ->where('room_id', '!=', $main)
            ->where('quantity_remaining', '>', 0)
            ->selectRaw('room_id, SUM(quantity_remaining) as units')
            ->groupBy('room_id')
            ->pluck('units', 'room_id');

        if ($elsewhere->isEmpty()) {
            return null;
        }

        $units = (int) $elsewhere->sum();

        $rooms = StockRoom::withTrashed()
            ->whereIn('id', $elsewhere->keys())
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');

        return sprintf(
            '%s %s in %s. Move %s to the main room first.',
            number_format($units),
            $units === 1 ? 'unit is' : 'units are',
            $rooms,
            $units === 1 ? 'it' : 'them',
        );
    }

    /** ⚠️ An open job is a customer's device sitting in the shop. */
    private static function openRepairs(): ?string
    {
        $open = Repair::query()->open()->count();

        if ($open === 0) {
            return null;
        }

        return sprintf(
            '%s %s open. A customer\'s device is in the shop.',
            number_format($open),
            $open === 1 ? 'repair job is' : 'repair jobs are',
        );
    }
}
