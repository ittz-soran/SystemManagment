<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            SettingSeeder::class,
            ExpenseCategorySeeder::class,
            CategorySeeder::class,
            CustomerSeeder::class,
            DocumentCounterSeeder::class,
        ]);

        // Section 2: admin has full access, always, and cannot be restricted —
        // so this account gets no user_permissions rows at all.
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => 'password',
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'language' => 'en',
                'theme' => 'auto',
                'items_per_page' => 25,
            ],
        );
    }
}
