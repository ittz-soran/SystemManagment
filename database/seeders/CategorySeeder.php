<?php

namespace Database\Seeders;

use App\Http\Controllers\ServiceController;
use App\Models\Category;
use App\Services\SecondHandService;
use Illuminate\Database\Seeder;

/**
 * The two categories the system itself puts things in.
 *
 * Second-hand items and services are created from their own screens rather than
 * the product form, and both still need a category. Seeding them means the
 * first one the shop records lands where it belongs instead of in whichever
 * category happens to sort first.
 *
 * Ordinary categories are the shop's own business; only these two are here.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([SecondHandService::DEFAULT_CATEGORY, ServiceController::DEFAULT_CATEGORY] as $name) {
            Category::firstOrCreate(['name' => __($name)]);
        }
    }
}
