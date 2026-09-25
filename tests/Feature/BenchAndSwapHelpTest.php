<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Repair;
use App\Models\User;
use App\Services\RepairService;
use App\Support\Guide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help for the bench and the swap screen — Soran, 2026-09-24.
 *
 * ⚠️ **These tests RENDER the screens.** The generic guide and screen-help
 * tests prove the entries are well formed; only opening the page proves the
 * help actually reaches the person standing on it — and only rendering catches
 * a Blade comment naming the `@@php` directive, which silently deletes
 * everything down to the next `@@endphp` while the page still answers 200.
 */
class BenchAndSwapHelpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    // ---- The ? button, on the screens that needed one ---------------------

    public function test_the_take_in_screen_has_its_help(): void
    {
        $this->actingAs($this->admin)
            ->get(route('repairs.create'))
            ->assertOk()
            ->assertSee(__('Help for this screen'))
            ->assertSee('id="screen-help"', false)
            ->assertSee(__('Taking a device in'))
            // ⚠️ The mistake people actually make here, said plainly.
            ->assertSee(__('It is what you told them at the counter, kept so you can see later how close you were. What they pay comes from the parts and labour put on the job, and they have to agree to that separately.'), false);
    }

    public function test_the_job_screen_has_its_help(): void
    {
        $repair = $this->job();

        $this->actingAs($this->admin)
            ->get(route('repairs.show', $repair))
            ->assertOk()
            ->assertSee(__('Help for this screen'))
            ->assertSee(__('Add a part after they agreed, and they have to agree again'))
            ->assertSee(__('Never put a line on the job for something you have not got — collection is refused, with the mended device on the counter.'), false);
    }

    /**
     * ⚠️ The swap screen is `Goods coming back` now — Soran, 2026-09-25. The
     * help moved with the flow rather than being left behind on a page nobody
     * can reach.
     */
    public function test_the_goods_coming_back_screen_has_its_help(): void
    {
        $this->actingAs($this->admin)
            ->get(route('goods-back.index'))
            ->assertOk()
            ->assertSee(__('Help for this screen'))
            ->assertSee(__('Only a swap leaves the invoice alone'))
            ->assertSee(__('Nothing on the shelf? The swap is not offered, and the screen says why rather than hiding the button.'), false);
    }

    /** And the page it replaced sends a bookmark on rather than 404ing. */
    public function test_the_old_swap_address_redirects_to_the_counter(): void
    {
        $this->actingAs($this->admin)
            ->get('/swaps/create')
            ->assertRedirect(route('goods-back.index'));
    }

    /**
     * ⚠️ The help carries no permission of its own — the reader most likely to
     * need it holds the fewest keys in the shop. A bench that may take a job in
     * still gets the help for taking a job in.
     */
    public function test_the_bench_gets_its_help_with_only_the_repair_keys(): void
    {
        $bench = User::factory()->create(['role' => User::ROLE_USER]);
        $bench->permissions()->sync(
            Permission::whereIn('key', ['repairs.view', 'repairs.create', 'repairs.edit', 'customers.view'])->pluck('id')
        );

        $this->actingAs($bench)
            ->get(route('repairs.create'))
            ->assertOk()
            ->assertSee(__('Help for this screen'))
            ->assertSee(__('Taking a device in'));
    }

    // ---- The guide --------------------------------------------------------

    /** Repairs is its own heading: the person reading it never opens the till. */
    public function test_repairs_is_a_heading_of_its_own_with_its_topics_under_it(): void
    {
        $this->assertArrayHasKey('repairs', Guide::groups());

        $slugs = array_keys(Guide::inGroup('repairs'));

        $this->assertSame([
            'repair-taking-in',
            'repair-doing-the-job',
            'repair-comes-back',
        ], $slugs);

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertSee(__('Repairs'))
            ->assertSee(__('Somebody brings in a broken device'))
            ->assertSee(__('Doing the job, and getting paid for it'))
            ->assertSee(__('A device you fixed comes back'));
    }

    /** The swap topic sits beside the return it is a cousin of. */
    public function test_the_swap_topic_is_under_selling(): void
    {
        $this->assertSame('selling', Guide::topic('faulty-swap')['group']);

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertSee(__('A faulty item comes back'));
    }

    /** Every new topic opens and has its body on the page. */
    public function test_the_new_topics_open(): void
    {
        $expected = [
            'repair-taking-in' => 'Already scratched, small dent on the corner, no charger. This is the line that settles an argument three weeks later about a mark nobody remembers. Take thirty seconds over it.',
            'repair-doing-the-job' => 'Nobody is charged for work they never agreed to.',
            'repair-comes-back' => 'A shop that forgets charges somebody twice for the same screen. That is the argument this exists to prevent.',
            'faulty-swap' => 'The customer bought one and still has one, so the paper they are holding stays true. What changed is which piece they have, and what is on your shelf.',
        ];

        foreach ($expected as $slug => $sentence) {
            $this->actingAs($this->admin)
                ->get(route('guide.show', $slug))
                ->assertOk()
                ->assertSee(Guide::topic($slug)['title'])
                ->assertSee(__($sentence), false);
        }
    }

    /**
     * ⚠️ A guide the newest assistant cannot open is the wrong way round: they
     * hold the fewest keys and need it most.
     */
    public function test_somebody_with_no_permissions_can_still_read_the_new_topics(): void
    {
        $nobody = User::factory()->create(['role' => User::ROLE_USER]);
        $nobody->permissions()->sync(Permission::where('key', 'auth.login')->pluck('id'));

        foreach (['repair-taking-in', 'repair-doing-the-job', 'repair-comes-back', 'faulty-swap'] as $slug) {
            $this->actingAs($nobody)->get(route('guide.show', $slug))->assertOk();
        }
    }

    /** What changed lately, in the shop's own words. */
    public function test_whats_new_names_the_bench_the_swap_and_the_find_page(): void
    {
        $titles = array_column(Guide::whatsNew(), 'title');

        $this->assertContains(__('Repairs, for anything with a fault'), $titles);
        $this->assertContains(__('A faulty item comes back'), $titles);
        $this->assertContains(__('One box that finds anything'), $titles);

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertSee(__('One box that finds anything'));
    }

    /** ⚠️ Three languages read these, and a missing key ships as English. */
    public function test_the_new_help_is_translated(): void
    {
        app()->setLocale('ckb');

        $this->assertNotSame('Taking a device in', __('Taking a device in'));
        $this->assertNotSame('A faulty item comes back', __('A faulty item comes back'));
        $this->assertNotSame(
            'Somebody brings in a broken device',
            __('Somebody brings in a broken device'),
        );
    }

    // ---- fixtures ---------------------------------------------------------

    private function job(): Repair
    {
        $customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        return app(RepairService::class)->create(
            customer: $customer,
            device: 'iPhone 13',
            identifier: 'IMEI-1',
            fault: 'Screen cracked',
            conditionNote: 'Scratched on the back',
            user: $this->admin,
            receivedAt: now(),
        );
    }
}
