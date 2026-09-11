<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\SchemaVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesItsOwnDatabase;

/**
 * A shop running code its own database has not caught up with.
 *
 * The shared codebase makes this normal for minutes and dangerous for days:
 * one `git pull` moves every shop's code at once, and each database waits for
 * `shop:update`. In between, the first screen to write a new column answers
 * 500 with nothing to explain it.
 *
 * Soran met it four times over — saving preferences, the authenticator,
 * changing language, logging out — and reported four separate faults, which is
 * exactly what one silent cause looks like from behind a counter. Reproduced
 * against real MariaDB by rolling the schema back four migrations:
 *
 *   SQLSTATE[42S22]: Unknown column 'date_language' in 'SET'
 *
 * There is no fixing that column, because the next release adds another. What
 * is fixed is the silence.
 */
class SchemaVersionTest extends TestCase
{
    // One of these drops the migrations table to see what the banner does
    // when it cannot ask. On MySQL that commits and leaves the shared schema
    // looking as though it had never been migrated, so it gets its own.
    use UsesItsOwnDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        app(SchemaVersion::class)->forget();
    }

    /** Pretend this shop never ran the last migration. */
    private function fallBehind(int $steps = 1): void
    {
        $ran = DB::table('migrations')->orderByDesc('id')->limit($steps)->pluck('id');

        DB::table('migrations')->whereIn('id', $ran)->delete();

        app(SchemaVersion::class)->forget();
    }

    public function test_a_shop_that_has_run_everything_is_not_behind(): void
    {
        $schema = app(SchemaVersion::class);

        $this->assertSame(0, $schema->pending());
        $this->assertFalse($schema->isBehind());
    }

    public function test_a_shop_that_missed_a_migration_is_behind(): void
    {
        $this->fallBehind(2);

        $this->assertSame(2, app(SchemaVersion::class)->pending());
        $this->assertTrue(app(SchemaVersion::class)->isBehind());
    }

    /** The whole point: it says so, on the page, before something breaks. */
    public function test_the_banner_tells_whoever_can_fix_it(): void
    {
        $this->fallBehind();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('This shop’s database is behind the system.'), false)
            ->assertSee('shop:update', false);
    }

    /**
     * Not to the person on the till.
     *
     * They cannot run the command, and a warning on every sale that nobody can
     * act on is how people learn to stop reading warnings.
     */
    public function test_the_banner_is_not_shown_to_somebody_who_cannot_act_on_it(): void
    {
        $this->fallBehind();

        $user = User::create([
            'name' => 'Karwan', 'email' => 'karwan@shop.iq',
            'password' => 'correct-horse-battery', 'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $user->permissions()->sync(Permission::whereIn('key', ['dashboard.view'])->pluck('id'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('This shop’s database is behind the system.'), false);
    }

    public function test_a_shop_that_is_up_to_date_is_not_nagged(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('This shop’s database is behind the system.'), false);
    }

    /**
     * The answer is cached, and `shop:update` clears it.
     *
     * A minute is nothing when the answer changes monthly — but it is a long
     * time to keep warning a shop that has just been fixed.
     */
    public function test_the_cached_answer_is_dropped_once_the_shop_is_updated(): void
    {
        $this->fallBehind();

        $this->assertTrue(app(SchemaVersion::class)->isBehind());
        $this->assertNotNull(Cache::get('schema.pending_migrations'));

        app(SchemaVersion::class)->forget();

        $this->assertNull(Cache::get('schema.pending_migrations'));
    }

    /**
     * A shop whose database cannot be reached has a bigger problem than a
     * banner, and must not be given a second one on the way down.
     */
    public function test_it_says_nothing_rather_than_throwing_when_it_cannot_ask(): void
    {
        Schema::drop('migrations');

        app(SchemaVersion::class)->forget();

        $this->assertSame(0, app(SchemaVersion::class)->pending());
        $this->assertFalse(app(SchemaVersion::class)->isBehind());
    }
}
