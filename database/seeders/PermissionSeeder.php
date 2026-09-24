<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Section 4: seeded once, not user-editable.
 *
 * The doc gives the default set and a handful of example keys but never
 * enumerates the full catalogue, so this list is derived from the Section 9
 * page list — one group per page, with the actions that page supports.
 */
class PermissionSeeder extends Seeder
{
    /** @var array<string, array<string, string>> */
    public const CATALOGUE = [
        'auth' => [
            'auth.login' => 'Log in',
        ],
        'dashboard' => [
            'dashboard.view' => 'View the dashboard',
        ],
        'products' => [
            'products.view' => 'View products',
            'products.create' => 'Create products',
            'products.edit' => 'Edit products',
            'products.delete' => 'Delete products',
        ],
        'categories' => [
            'categories.view' => 'View categories',
            'categories.create' => 'Create categories',
            'categories.edit' => 'Edit categories',
            'categories.delete' => 'Delete categories',
        ],
        'suppliers' => [
            'suppliers.view' => 'View suppliers',
            'suppliers.create' => 'Create suppliers',
            'suppliers.edit' => 'Edit suppliers',
            'suppliers.delete' => 'Delete suppliers',
        ],
        'repairs' => [
            'repairs.view' => 'View repair jobs',
            'repairs.create' => 'Take in a repair',
            'repairs.edit' => 'Work on a repair',
            'repairs.delete' => 'Delete a repair',

            /*
             * ⚠️ Spending the shop's money is not the same power as working on
             * a repair — Soran, 2026-09-23. A screen bought from the shop down
             * the street is a real purchase, made from the repair screen, and
             * an owner who does not want every bench hand creating purchases
             * takes this one key away and leaves the rest.
             */
            'repairs.buy_part' => 'Buy a part for a job',
        ],
        'customers' => [
            'customers.view' => 'View customers',
            'customers.create' => 'Create customers',
            'customers.edit' => 'Edit customers',
            'customers.delete' => 'Delete customers',
        ],
        'users' => [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit users',
            'users.delete' => 'Delete users',
        ],
        'purchases' => [
            'purchases.view' => 'View purchases',
            'purchases.create' => 'Create purchases',
            'purchases.edit' => 'Edit purchases',
            'purchases.delete' => 'Delete purchases',
        ],
        'sales' => [
            'sales.view' => 'View sales',
            'sales.create' => 'Create sales',
            'sales.edit' => 'Edit sales',
            'sales.delete' => 'Delete sales',
        ],
        'sale_returns' => [
            'sale_returns.view' => 'View sale returns',
            'sale_returns.create' => 'Create sale returns',
            'sale_returns.delete' => 'Delete sale returns',
        ],
        /*
         * ⚠️ Its own keys — Soran, 2026-09-23. A swap moves stock AND bills a
         * supplier, which is more than taking a return and more than selling.
         * Somebody trusted with one is not thereby trusted with this.
         */
        'swaps' => [
            'swaps.view' => 'View swaps',
            'swaps.create' => 'Swap a faulty item',
        ],
        'purchase_returns' => [
            'purchase_returns.view' => 'View purchase returns',
            'purchase_returns.create' => 'Create purchase returns',
            'purchase_returns.delete' => 'Delete purchase returns',
        ],
        'payments' => [
            'payments.view' => 'View payments',
            'payments.create' => 'Record payments',
            'payments.edit' => 'Edit payments',
            'payments.delete' => 'Delete payments',
        ],
        'expenses' => [
            'expenses.view' => 'View expenses',
            'expenses.create' => 'Create expenses',
            'expenses.edit' => 'Edit expenses',
            'expenses.delete' => 'Delete expenses',
        ],
        'expense_categories' => [
            'expense_categories.manage' => 'Manage expense categories',
        ],
        'stock_adjustments' => [
            'stock_adjustments.view' => 'View stock adjustments',
            'stock_adjustments.create' => 'Create stock adjustments',
            'stock_adjustments.edit' => 'Edit stock adjustments',
            'stock_adjustments.delete' => 'Delete stock adjustments',
        ],
        'stock' => [
            'stock.recheck' => 'Recheck stock against batch sums',
        ],
        /*
         * Stock rooms — Soran, 2026-09-15.
         *
         * ⚠️ Three keys, not one, and the split is the point. Seeing which room
         * holds what is something a counter assistant needs in order to answer
         * "have you got one out the back". Moving goods between rooms, and
         * adding or closing a room, are not — those change where the shop's
         * stock is, and a shop should be able to say who may do that.
         */
        'stock_rooms' => [
            'stock_rooms.view' => 'See stock rooms and what each holds',
            'stock_rooms.manage' => 'Add, rename and close stock rooms',
            'stock_rooms.transfer' => 'Move stock between rooms',
        ],
        /*
         * Three screens that used to ride on products.view, which meant they
         * could not be withheld from anybody allowed to look at the catalogue —
         * and there was nothing on the permissions page to withhold.
         *
         * Only the screens. Buying a second-hand item is still purchases.create
         * and changing a service is still products.edit: what the act does to
         * the books has not moved, only who gets shown the door to it.
         */
        'second_hand' => [
            'second_hand.view' => 'View second-hand items',
        ],
        'services' => [
            'services.view' => 'View services',
        ],
        'data' => [
            'data.manage' => 'Import and export data',
        ],
        'reports' => [
            'reports.view' => 'View reports and statistics',
        ],
        'activity_logs' => [
            'activity_logs.view' => 'View the activity log',
        ],
        'settings' => [
            // Section 8c: guards shop info, appearance, timezone, USD rate,
            // books_closed_before and backups — values that change invoices,
            // costing and the edit window across the whole system.
            'settings.manage' => 'Manage settings',
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $group => $keys) {
            foreach ($keys as $key => $label) {
                Permission::updateOrCreate(
                    ['key' => $key],
                    ['group' => $group, 'label' => $label],
                );
            }
        }
    }
}
