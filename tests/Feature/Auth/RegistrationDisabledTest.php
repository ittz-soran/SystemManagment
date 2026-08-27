<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 9: users are created by an admin, never by self-registration.
 *
 * Breeze ships a public /register route; leaving it in place would let anyone
 * who reaches the shop's URL create themselves an account, so it is removed.
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_route_does_not_exist(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('register'),
            'A public registration route must not exist in a shop system'
        );

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'shop-till-2026',
            'password_confirmation' => 'shop-till-2026',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_an_admin_creates_users_instead(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Shop Assistant',
            'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026',
            'password_confirmation' => 'a-strong-password-2026',
            'role' => 'user',
            'is_active' => 1,
            'language' => 'ckb',
            'theme' => 'auto',
            'items_per_page' => 25,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'assistant@example.com')->firstOrFail();

        // Section 4: a new user starts from the default permission set.
        $this->assertSame(
            count(User::DEFAULT_PERMISSIONS),
            $user->permissions()->count(),
        );
    }
}
