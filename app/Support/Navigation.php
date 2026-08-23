<?php

namespace App\Support;

use App\Models\User;

/**
 * The shop's own map: every screen, what it is called, and who may open it.
 *
 * It lives here rather than in the sidebar template because two things read it
 * now — the sidebar draws it, and the search box lets a reader jump to a screen
 * by name. Written twice, a screen added to one would be missing from the other,
 * and the sidebar is exactly where a missing screen is hardest to notice.
 *
 * Section 9b: "Only show nav items the user has permission for — never show a
 * link that leads to 'access denied'." That holds for both readers of this list.
 */
final class Navigation
{
    /**
     * @return array<string|int, list<array{route: string, permission: string, icon: string, label: string}>>
     */
    public static function groups(): array
    {
        return [
            '' => [
                ['route' => 'dashboard', 'permission' => 'dashboard.view', 'icon' => 'speedometer2', 'label' => __('Dashboard')],
            ],
            __('Sell & buy') => [
                ['route' => 'sales.create', 'permission' => 'sales.create', 'icon' => 'cart-plus', 'label' => __('New sale')],
                ['route' => 'purchases.create', 'permission' => 'purchases.create', 'icon' => 'bag-plus', 'label' => __('New purchase')],
                ['route' => 'sales.index', 'permission' => 'sales.view', 'icon' => 'receipt', 'label' => __('Sales history')],
                ['route' => 'purchases.index', 'permission' => 'purchases.view', 'icon' => 'journal-text', 'label' => __('Purchase history')],
                ['route' => 'sale-returns.index', 'permission' => 'sale_returns.view', 'icon' => 'arrow-return-left', 'label' => __('Sale returns')],
                ['route' => 'purchase-returns.index', 'permission' => 'purchase_returns.view', 'icon' => 'arrow-return-right', 'label' => __('Purchase returns')],
            ],
            __('Catalogue') => [
                ['route' => 'products.index', 'permission' => 'products.view', 'icon' => 'box-seam', 'label' => __('Products')],
                ['route' => 'categories.index', 'permission' => 'categories.view', 'icon' => 'tags', 'label' => __('Categories')],
                ['route' => 'second-hand.index', 'permission' => 'products.view', 'icon' => 'arrow-repeat', 'label' => __('Second-hand')],
                ['route' => 'services.index', 'permission' => 'products.view', 'icon' => 'magic', 'label' => __('Services')],
                ['route' => 'stock-adjustments.index', 'permission' => 'stock_adjustments.view', 'icon' => 'sliders', 'label' => __('Stock adjustments')],
            ],
            __('People') => [
                ['route' => 'customers.index', 'permission' => 'customers.view', 'icon' => 'people', 'label' => __('Customers')],
                ['route' => 'suppliers.index', 'permission' => 'suppliers.view', 'icon' => 'truck', 'label' => __('Suppliers')],
                ['route' => 'users.index', 'permission' => 'users.view', 'icon' => 'person-badge', 'label' => __('Users')],
            ],
            __('Money') => [
                ['route' => 'payments.index', 'permission' => 'payments.view', 'icon' => 'cash-coin', 'label' => __('Payments')],
                ['route' => 'expenses.index', 'permission' => 'expenses.view', 'icon' => 'cash-stack', 'label' => __('Expenses')],
                ['route' => 'reports.index', 'permission' => 'reports.view', 'icon' => 'graph-up', 'label' => __('Reports')],
            ],
            __('System') => [
                ['route' => 'activity-logs.index', 'permission' => 'activity_logs.view', 'icon' => 'clock-history', 'label' => __('Activity log')],
                ['route' => 'data.index', 'permission' => 'products.view', 'icon' => 'arrow-down-up', 'label' => __('Import & export')],
                ['route' => 'settings.edit', 'permission' => 'settings.manage', 'icon' => 'gear', 'label' => __('Settings')],
            ],
        ];
    }

    /**
     * Every screen this user may open, flat, for searching by name.
     *
     * @return list<array{route: string, icon: string, label: string, group: string}>
     */
    public static function pagesFor(User $user): array
    {
        $pages = [];

        foreach (self::groups() as $group => $items) {
            foreach ($items as $item) {
                if ($user->hasPermission($item['permission'])) {
                    $pages[] = [...$item, 'group' => (string) $group];
                }
            }
        }

        return $pages;
    }
}
