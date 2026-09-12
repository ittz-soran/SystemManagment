<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who owes, not just how much — asked for by Soran, 2026-09-12.
 *
 * The card said 222,000 and nothing else, with half of itself empty; he drew a
 * question mark in the space. A single figure cannot tell a shopkeeper the one
 * thing that decides what to do about it: whether that is one customer who has
 * not paid, or twenty who each owe a little. The first is a phone call this
 * afternoon; the second is how a shop works.
 */
class WhoOwesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();

        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_it_names_the_largest_debtors_and_counts_them(): void
    {
        $admin = $this->admin();

        foreach ([['Hawkar Osman', 180_000], ['Bawan Sara Store', 42_000], ['Pola', 7_000]] as [$name, $owes]) {
            Customer::create(['name' => $name])->forceFill(['balance' => $owes])->save();
        }

        $page = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $page->assertSee('Hawkar Osman')
            ->assertSee('Bawan Sara Store')
            ->assertSee(trans_choice('{0}Nobody|{1}:count account|[2,*]:count accounts', 3, ['count' => 3]));
    }

    /** Three, because this sits in half a card beside a figure. */
    public function test_it_names_at_most_three(): void
    {
        $admin = $this->admin();

        foreach (range(1, 6) as $n) {
            Customer::create(['name' => "Debtor {$n}"])->forceFill(['balance' => $n * 1_000])->save();
        }

        $body = $this->actingAs($admin)->get(route('dashboard'))->getContent();

        // The three largest, and not the three smallest.
        foreach ([6, 5, 4] as $n) {
            $this->assertStringContainsString("Debtor {$n}", $body);
        }

        $this->assertStringNotContainsString('Debtor 1', $body);
    }

    /**
     * Only the accounts that actually owe are counted.
     *
     * ⚠️ Not about negative balances: the suite's own invariant check forbids
     * those outright — a first version of this test manufactured one and was
     * told off, correctly, by Section 4. What `> 0` is really doing is keeping
     * the SETTLED accounts out of the count. A shop with four hundred customers
     * and two debts must read "2 accounts", not "400".
     */
    public function test_settled_accounts_are_not_counted(): void
    {
        $admin = $this->admin();

        Supplier::create(['name' => 'Owed'])->forceFill(['balance' => 100_000])->save();
        Supplier::create(['name' => 'Paid up']);   // balance 0
        Supplier::create(['name' => 'Also paid up']);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(trans_choice('{0}Nobody|{1}:count account|[2,*]:count accounts', 1, ['count' => 1]))
            ->assertDontSee('Paid up');
    }

    /**
     * ⚠️ The card stays for a reader who may not see it, masked.
     *
     * Section 2, and SecurityTest says it in words: "a missing one says the
     * shop has no such figure; a masked one says there is one and it is not
     * theirs." A first version of this deleted the card outright, and the suite
     * caught it.
     */
    public function test_a_reader_without_the_permission_sees_the_card_masked_and_no_names(): void
    {
        $this->seed();

        Customer::create(['name' => 'Hawkar Osman'])->forceFill(['balance' => 180_000])->save();

        $user = User::create([
            'name' => 'Till', 'email' => 'till@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $user->permissions()->sync(Permission::whereIn('key', ['dashboard.view'])->pluck('id')->all());

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('Customers owe the shop'))
            ->assertSee(hidden_money())
            ->assertDontSee('Hawkar Osman');
    }
}
