<?php

namespace App\Support;

/**
 * Somewhere to start when a new person is added.
 *
 * The permissions page is sixty-odd checkboxes with no order of importance, and
 * the shop hires a person at the counter far more often than it invents a new
 * kind of job. So the four jobs it actually has are written down, and the
 * admin starts from the nearest one and adjusts — rather than reading every
 * line and hoping they remembered the one that matters.
 *
 * A starting point and nothing more: nothing is stored against the user, and
 * ticking one only moves the boxes. What is saved is whatever is ticked when
 * the form is saved, exactly as before.
 *
 * Deliberately not in the database. These describe how a shop is staffed, not
 * data the shop keeps, and a preset somebody edited into nonsense is a worse
 * problem than a preset that needed a code change.
 */
final class StaffPresets
{
    /**
     * @return array<string, array{label: string, note: string, keys: list<string>}>
     */
    public static function all(): array
    {
        return [
            'counter' => [
                'label' => __('At the counter'),
                'note' => __('Sells, and can look things up. Sees no cost and no purchase.'),
                'keys' => [
                    'auth.login', 'dashboard.view',
                    'sales.view', 'sales.create',
                    'sale_returns.view', 'sale_returns.create',
                    'products.view', 'second_hand.view', 'services.view',
                    'customers.view', 'customers.create',
                    'payments.view', 'payments.create',

                    /*
                     * ⚠️ Seeing where stock is, without being able to move it.
                     *
                     * When the till refuses a sale it says "Another 5 are in
                     * other rooms — transfer them first". Without this key that
                     * sentence points at a door the person cannot open, and the
                     * question it answers — "have you got one out the back" —
                     * is asked at the counter more than anywhere else.
                     */
                    'stock_rooms.view',
                ],
            ],

            /*
             * ⚠️ Soran, 2026-09-22: "every technician or repair person should
             * have acc with repairing permissions and take jobs from
             * customers".
             *
             * Both halves of that sentence are in here. `repairs.create` is the
             * counter half — taking the device in — and `repairs.edit` the
             * bench half, so one person does the whole job without an owner in
             * the middle. `repairs.edit` is also the key that decides who may
             * be GIVEN a job, so a person without it is invisible on the "who
             * will do it" list however many other keys they hold.
             *
             * ⚠️ No `sales.*`, and the job still gets paid for: collecting is a
             * sale, and it is taken at the counter by somebody who sells. A
             * bench that could sell could also collect its own work, which is
             * the one place in this module the money moves.
             */
            'bench' => [
                'label' => __('Mends things'),
                'note' => __('Takes repairs in and works on them. Does not sell, and does not take the money.'),
                'keys' => [
                    'auth.login', 'dashboard.view',
                    'repairs.view', 'repairs.create', 'repairs.edit',

                    // The parts a job needs are products, and a job cannot be
                    // quoted by somebody who cannot look one up.
                    'products.view',

                    // Whose device it is, and how to telephone them when the
                    // drive turns out to be failing.
                    'customers.view', 'customers.create',

                    // "Have we got one out the back" is asked at the bench as
                    // often as at the counter.
                    'stock_rooms.view',
                ],
            ],

            'stock' => [
                'label' => __('Looks after the stock'),
                'note' => __('Buys, counts and corrects the shelves. Sees what things cost.'),
                'keys' => [
                    'auth.login', 'dashboard.view',
                    'products.view', 'products.create', 'products.edit',
                    'categories.view', 'categories.create', 'categories.edit',
                    'second_hand.view', 'services.view',
                    'suppliers.view', 'suppliers.create', 'suppliers.edit',
                    'purchases.view', 'purchases.create', 'purchases.edit',
                    'purchase_returns.view', 'purchase_returns.create',
                    'stock_adjustments.view', 'stock_adjustments.create', 'stock_adjustments.edit',
                    'stock.recheck',

                    // This is the person who carries the crates. Moving stock
                    // between rooms is the job, not an extra.
                    'stock_rooms.view', 'stock_rooms.transfer',
                ],
            ],

            'manager' => [
                'label' => __('Runs the shop'),
                'note' => __('Everything except the staff list and the settings.'),
                'keys' => [], // Filled below: everything but the two an owner keeps.
            ],
        ];
    }

    /**
     * The manager's set is "everything except", so it is worked out from the
     * catalogue rather than typed out and left to go stale as keys are added.
     *
     * @param  list<string>  $everyKey
     * @return array<string, array{label: string, note: string, keys: list<string>}>
     */
    public static function resolved(array $everyKey): array
    {
        $presets = self::all();

        $presets['manager']['keys'] = array_values(array_diff($everyKey, [
            // The staff list is admin-only anyway, and the settings change
            // costing and the edit window for the whole shop.
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'settings.manage',
        ]));

        return $presets;
    }
}
