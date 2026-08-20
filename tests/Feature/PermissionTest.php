<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Section 2 and Section 4: two roles, per-user permissions. */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Section 2: admin has full access, always. Cannot be restricted. */
    public function test_admin_short_circuits_every_permission_check(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->isAdmin());
        $this->assertSame(0, $admin->permissions()->count(), 'Admin needs no permission rows at all');

        foreach (Permission::pluck('key') as $key) {
            $this->assertTrue($admin->hasPermission($key), "Admin must hold {$key}");
        }

        // Even a key that does not exist.
        $this->assertTrue($admin->hasPermission('anything.at.all'));
    }

    public function test_a_new_user_gets_only_the_default_set(): void
    {
        $user = $this->makeUserWithDefaults();

        foreach (User::DEFAULT_PERMISSIONS as $key) {
            $this->assertTrue($user->hasPermission($key), "Default set must include {$key}");
        }

        // Not in the default set — editing and deleting are opt-in.
        $this->assertFalse($user->hasPermission('sales.edit'));
        $this->assertFalse($user->hasPermission('purchases.delete'));
        $this->assertFalse($user->hasPermission('settings.manage'));
        $this->assertFalse($user->hasPermission('users.view'));
    }

    /** Section 4: the admin then adds or removes individual permissions freely. */
    public function test_permissions_can_be_added_and_removed_individually(): void
    {
        $user = $this->makeUserWithDefaults();

        $editSales = Permission::where('key', 'sales.edit')->firstOrFail();
        $user->permissions()->attach($editSales);
        $user->load('permissions');

        $this->assertTrue($user->hasPermission('sales.edit'));

        $user->permissions()->detach($editSales);
        $user->load('permissions');

        $this->assertFalse($user->hasPermission('sales.edit'));
    }

    public function test_an_inactive_user_holds_nothing(): void
    {
        $user = $this->makeUserWithDefaults();
        $user->forceFill(['is_active' => false])->save();

        $this->assertFalse($user->hasPermission('sales.create'), 'A deactivated account can do nothing');
    }

    /** The Gate and the model must never disagree — there is one source. */
    public function test_the_gate_matches_the_model(): void
    {
        $user = $this->makeUserWithDefaults();

        $this->assertTrue(Gate::forUser($user)->allows('sales.create'));
        $this->assertFalse(Gate::forUser($user)->allows('sales.edit'));

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Gate::forUser($admin)->allows('sales.edit'));
    }

    public function test_the_route_middleware_blocks_and_allows_correctly(): void
    {
        $user = $this->makeUserWithDefaults();

        \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'permission:sales.edit'])
            ->get('/_test/guarded', fn () => 'ok');

        \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'permission:sales.create'])
            ->get('/_test/allowed', fn () => 'ok');

        $this->actingAs($user)->get('/_test/guarded')->assertForbidden();
        $this->actingAs($user)->get('/_test/allowed')->assertOk();
    }

    /** Every key in the default set must actually exist in the catalogue. */
    public function test_the_default_set_references_real_permissions(): void
    {
        $known = Permission::pluck('key')->all();

        foreach (User::DEFAULT_PERMISSIONS as $key) {
            $this->assertContains($key, $known, "{$key} is in the default set but not seeded");
        }

        $catalogueSize = collect(PermissionSeeder::CATALOGUE)->flatten(1)->count();
        $this->assertSame($catalogueSize, Permission::count());
    }

    private function makeUserWithDefaults(): User
    {
        $user = User::create([
            'name' => 'Shop Assistant',
            'email' => 'assistant@example.com',
            'password' => 'password',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
        ]);

        $user->permissions()->sync(
            Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')
        );

        return $user->load('permissions');
    }
}
