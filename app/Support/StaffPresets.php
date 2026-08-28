<?php

namespace App\Support;

/**
 * Somewhere to start when a new person is added.
 *
 * The permissions page is sixty-odd checkboxes with no order of importance, and
 * the shop hires a person at the counter far more often than it invents a new
 * kind of job. So the three jobs it actually has are written down, and the
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
