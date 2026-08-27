<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

        /*
         * Section 2: admin has full access, always, and cannot be restricted —
         * so this account gets no user_permissions rows at all.
         *
         * Its password is not written here. Every copy of this system used to
         * install with the same one, which was survivable while the only way in
         * was a keyboard in the shop and is not survivable now that the address
         * is on the internet: the account that opens every screen would be
         * standing behind a password anybody reading this file already knows.
         *
         * Set ADMIN_PASSWORD in .env to choose it. Leave it unset and a random
         * one is made and printed once, here, at install — the only time it is
         * ever shown.
         */
        $chosen = trim((string) env('ADMIN_PASSWORD', ''));
        $password = $chosen !== '' ? $chosen : Str::password(16, symbols: false);

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'language' => 'en',
                'theme' => 'auto',
                'items_per_page' => 25,
            ],
        );

        if ($admin->wasRecentlyCreated && $chosen === '' && ! app()->runningUnitTests()) {
            $this->command?->newLine();
            $this->command?->warn('  Administrator account: admin@example.com');
            $this->command?->warn('  Password: '.$password);
            $this->command?->warn('  Write it down now — it is not shown again. Change it at /profile.');
            $this->command?->newLine();
        }
    }
}
