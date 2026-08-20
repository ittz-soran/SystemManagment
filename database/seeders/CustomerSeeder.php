<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Section 4: the Cash Customer. Default on the sale form for walk-in buyers,
 * cannot be deleted or renamed, and must always be paid in full (no loan).
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::firstOrCreate(
            ['is_system' => true],
            ['name' => 'Cash Customer', 'balance' => 0, 'is_active' => true],
        );
    }
}
