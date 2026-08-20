<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/** Section 4: the seeded list. Admin can add more or deactivate these. */
class ExpenseCategorySeeder extends Seeder
{
    public const DEFAULTS = [
        'Rent',
        'Utilities',
        'Salaries',
        'Transport',
        'Maintenance',
        'Supplies',
        'Government fees',
        'Other',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
