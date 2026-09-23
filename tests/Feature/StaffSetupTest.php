<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Support\Navigation;
use App\Support\StaffPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setting somebody up, and being able to read back who set them up that way.
 *
 * The permissions page is sixty-odd checkboxes with no order of importance.
 * Presets give the three jobs the shop actually has, the preview answers the
 * question an admin is really asking — what will this person see when they sign
 * in — and the log finally records the one change on this screen that was
 * leaving no trace at all.
 */
class StaffSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** A preset naming a key that does not exist would silently do nothing. */
    public function test_every_preset_names_real_permissions(): void
    {
        $known = Permission::pluck('key')->all();

        foreach (StaffPresets::resolved($known) as $name => $preset) {
            $this->assertNotEmpty($preset['keys'], "The {$name} preset is empty");

            foreach ($preset['keys'] as $key) {
                $this->assertContains($key, $known, "The {$name} preset names {$key}, which is not seeded");
            }
        }
    }

    /**
     * ⚠️ The bench takes jobs in and works on them, and does not touch money.
     *
     * Soran, 2026-09-22: *"every repair person should have acc with repairing
     * permissions and take jobs from customers"*. `repairs.edit` is also what
     * decides who may be given a job, so a preset without it would produce
     * accounts that cannot appear on their own "who will do it" list.
     *
     * No `sales.*`: collecting a repair IS a sale, and it is the one moment in
     * the module when stock and money move.
     */
    public function test_the_bench_preset_can_take_a_job_in_and_work_on_it_but_not_sell(): void
    {
        $bench = StaffPresets::resolved(Permission::pluck('key')->all())['bench']['keys'];

        foreach (['repairs.view', 'repairs.create', 'repairs.edit', 'products.view', 'customers.create'] as $needed) {
            $this->assertContains($needed, $bench);
        }

        foreach (['sales.create', 'purchases.view', 'reports.view', 'settings.manage'] as $withheld) {
            $this->assertNotContains($withheld, $bench);
        }
    }

    /** The counter sells and looks things up, and buys nothing. */
    public function test_the_counter_preset_keeps_the_purchase_side_out(): void
    {
        $counter = StaffPresets::resolved(Permission::pluck('key')->all())['counter']['keys'];

        $this->assertContains('sales.create', $counter);
        $this->assertContains('products.view', $counter);

        foreach (['purchases.view', 'purchases.create', 'reports.view', 'settings.manage'] as $withheld) {
            $this->assertNotContains($withheld, $counter);
        }
    }

    /**
     * ⚠️ **The two written-out presets went stale, exactly as their own comment
     * warned they would — Soran, 2026-09-18: "recheck user permissions because
     * we added some new pages".**
     *
     * Stock rooms arrived with three keys and neither hand-written preset
     * gained any of them. The manager's set is "everything except" and followed
     * on its own; these two are typed out and did not.
     *
     * Named one screen at a time rather than "must contain every new key",
     * because whether a job needs a new permission is a judgement about the
     * job — the only useful test is one that says what this person has to be
     * able to do and why.
     */
    public function test_the_counter_can_see_where_the_stock_is(): void
    {
        $counter = StaffPresets::resolved(Permission::pluck('key')->all())['counter']['keys'];

        // The till's own refusal says "Another 5 are in other rooms — transfer
        // them first". Without this, that sentence points at a locked door.
        $this->assertContains('stock_rooms.view', $counter);

        // Seeing where it is, not moving it, and not opening or closing a room.
        $this->assertNotContains('stock_rooms.transfer', $counter);
        $this->assertNotContains('stock_rooms.manage', $counter);
    }

    /** The person who carries the crates has to be able to carry the crates. */
    public function test_the_stock_keeper_can_move_stock_between_rooms(): void
    {
        $stock = StaffPresets::resolved(Permission::pluck('key')->all())['stock']['keys'];

        $this->assertContains('stock_rooms.view', $stock);
        $this->assertContains('stock_rooms.transfer', $stock);

        // Adding and closing rooms is a shape-of-the-shop decision, not a
        // day's work, so it stays with the manager.
        $this->assertNotContains('stock_rooms.manage', $stock);
    }

    /** "Everything except" is worked out from the catalogue, so it cannot go stale. */
    public function test_the_manager_preset_covers_every_key_but_the_owners_own(): void
    {
        $known = Permission::pluck('key')->all();
        $manager = StaffPresets::resolved($known)['manager']['keys'];

        $this->assertSame(
            [],
            array_diff($known, $manager, ['users.view', 'users.create', 'users.edit', 'users.delete', 'settings.manage']),
            'A key was added to the catalogue and the manager preset did not follow',
        );
    }

    public function test_the_form_offers_the_presets_and_the_menu_to_preview(): void
    {
        $response = $this->actingAs($this->admin)->get(route('users.create'))->assertOk();

        $response->assertSee(__('Start from'))
            ->assertSee(__('At the counter'))
            ->assertSee(__('The menu they will see'))
            ->assertSee('id="menu-preview"', false);

        // The preview is drawn from the same map the sidebar is, so every
        // screen in it has to reach the page.
        foreach (collect(Navigation::groups())->flatten(1)->take(4) as $screen) {
            $response->assertSee($screen['label']);
        }
    }

    /**
     * Permissions are rows in another table, not columns, so the activity log
     * followed none of it — the one change on this screen most worth a record.
     */
    public function test_giving_and_taking_a_permission_is_recorded(): void
    {
        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::whereIn('key', ['products.view', 'sales.view'])->pluck('id')->all());

        $this->actingAs($this->admin)->put(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => User::ROLE_USER,
            'cost_visibility' => User::COST_REAL,
            'is_active' => 1,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
            'permissions' => Permission::whereIn('key', ['products.view', 'reports.view'])->pluck('id')->all(),
        ])->assertRedirect(route('users.index'));

        $entry = ActivityLog::where('module', 'users')
            ->where('record_id', $staff->id)
            ->where('action', 'update')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'A permission change has to leave a trail');
        $this->assertStringContainsString('reports.view', $entry->description);
        $this->assertStringContainsString('sales.view', $entry->description);
    }

    /** A password's old hash is in the log and must never reach a screen. */
    public function test_the_history_never_prints_a_password_hash(): void
    {
        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);

        $before = $staff->password;

        $this->actingAs($this->admin);
        $staff->update(['password' => 'another-strong-password-2026']);

        $this->actingAs($this->admin)->get(route('users.edit', $staff))
            ->assertOk()
            ->assertSee(__('History'))
            ->assertDontSee($before)
            ->assertDontSee('$2y$');
    }
}
