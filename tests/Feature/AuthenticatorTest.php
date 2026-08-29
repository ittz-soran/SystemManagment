<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The way back in when the password is forgotten and there is no email.
 *
 * MAIL_MAILER is `log` on every install of this system, so Laravel's own reset
 * link has never left the building — which was survivable while the only user
 * was the person who built it, and stopped being survivable the moment it was
 * sold. An owner who forgets their password has nobody to ask, and the whole
 * shop's records are behind it.
 *
 * Most of this is about the ways it must NOT work: a code that opens any
 * account, a million guesses, a screen somebody walked away from.
 */
class AuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->admin->forceFill(['password' => 'a-strong-password-2026'])->save();
    }

    /** An account with the phone already enrolled. */
    private function enrolled(User $user): string
    {
        $secret = Totp::secret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    // =====================================================================
    // Setting it up
    // =====================================================================

    public function test_the_setup_screen_offers_a_square_and_the_letters_under_it(): void
    {
        $this->actingAs($this->admin)->get(route('authenticator.show'))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee(__('If the camera will not focus, type this in by hand instead:'), false);
    }

    /**
     * Nothing is written until a code comes back correct.
     *
     * A secret that was generated but never proved is worse than none: it reads
     * as a way in, on a screen, and is not one.
     */
    public function test_a_secret_is_not_written_until_a_code_proves_it(): void
    {
        $this->actingAs($this->admin)->get(route('authenticator.show'))->assertOk();

        $this->assertNull($this->admin->fresh()->two_factor_secret, 'nothing written yet');

        $secret = session('authenticator.secret');

        $this->post(route('authenticator.confirm'), ['code' => Totp::at($secret)])
            ->assertRedirect(route('authenticator.show'));

        $this->assertTrue($this->admin->fresh()->hasAuthenticator());
    }

    public function test_a_wrong_code_turns_nothing_on(): void
    {
        $this->actingAs($this->admin)->get(route('authenticator.show'))->assertOk();

        $this->post(route('authenticator.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->admin->fresh()->hasAuthenticator());
    }

    public function test_turning_it_on_hands_over_eight_codes_once(): void
    {
        $this->actingAs($this->admin)->get(route('authenticator.show'))->assertOk();

        $this->post(route('authenticator.confirm'), ['code' => Totp::at(session('authenticator.secret'))]);

        $codes = session('recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));

        // On the screen this once...
        $this->followingRedirects()
            ->get(route('authenticator.show'))
            ->assertSee($codes[0], false);

        // ...and never again.
        $this->get(route('authenticator.show'))->assertDontSee($codes[0], false);
    }

    /** The secret does not sit in the database in the clear. */
    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $secret = $this->enrolled($this->admin);

        $stored = \DB::table('users')->where('id', $this->admin->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, (string) $stored);
        $this->assertSame($secret, $this->admin->fresh()->two_factor_secret, 'and still readable by the app');
    }

    /** A screen somebody walked away from is not a way to remove it. */
    public function test_turning_it_off_takes_the_current_password(): void
    {
        $this->enrolled($this->admin);

        $this->actingAs($this->admin)
            ->delete(route('authenticator.destroy'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->admin->fresh()->hasAuthenticator());

        $this->delete(route('authenticator.destroy'), ['password' => 'a-strong-password-2026'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->admin->fresh()->hasAuthenticator());
    }

    // =====================================================================
    // Getting back in
    // =====================================================================

    public function test_the_phone_sets_a_new_password(): void
    {
        $secret = $this->enrolled($this->admin);

        $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => Totp::at($secret),
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-password-2026', $this->admin->fresh()->password));
    }

    /** The phone in the river: one of the written codes does it instead. */
    public function test_a_recovery_code_does_it_when_the_phone_is_gone(): void
    {
        $this->enrolled($this->admin);

        $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => 'AAAAA-BBBBB',
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-password-2026', $this->admin->fresh()->password));
    }

    /** Once. A code read over somebody's shoulder is worth nothing afterwards. */
    public function test_a_recovery_code_only_works_once(): void
    {
        $this->enrolled($this->admin);

        $use = fn () => $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => 'AAAAA-BBBBB',
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ]);

        $use()->assertRedirect(route('login'));

        $this->assertSame(['CCCCC-DDDDD'], array_values($this->admin->fresh()->two_factor_recovery_codes));

        $use()->assertSessionHasErrors('code');
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $this->enrolled($this->admin);

        $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => '000000',
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('a-strong-password-2026', $this->admin->fresh()->password));
    }

    /** One account's phone must not open another account. */
    public function test_one_persons_code_does_not_open_somebody_elses_account(): void
    {
        $mine = $this->enrolled($this->admin);

        $other = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $this->enrolled($other);

        $this->post(route('password.recover.update'), [
            'email' => 'assistant@example.com',
            'code' => Totp::at($mine),
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('a-strong-password-2026', $other->fresh()->password));
    }

    /** An account with no authenticator cannot be reset this way at all. */
    public function test_an_account_without_an_authenticator_cannot_be_recovered(): void
    {
        $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => '123456',
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('a-strong-password-2026', $this->admin->fresh()->password));
    }

    /**
     * The refusal says the same thing every time.
     *
     * Otherwise this screen answers "does this address have an account here?"
     * to anybody who asks it.
     */
    public function test_the_refusal_does_not_say_which_part_was_wrong(): void
    {
        $this->enrolled($this->admin);

        $attempts = [
            ['nobody@example.com', '123456'],       // no such account
            ['admin@example.com', '000000'],        // right account, wrong code
        ];

        $messages = [];

        foreach ($attempts as [$email, $code]) {
            RateLimiter::clear('recover|'.$email.'|127.0.0.1');

            $messages[] = $this->post(route('password.recover.update'), [
                'email' => $email, 'code' => $code,
                'password' => 'a-brand-new-password-2026',
                'password_confirmation' => 'a-brand-new-password-2026',
            ])->assertSessionHasErrors('code')
                ->getSession()->get('errors')->first('code');
        }

        $this->assertSame($messages[0], $messages[1], 'both refusals read the same');
    }

    /** Six digits is a million guesses, and a million guesses must take forever. */
    public function test_guessing_is_shut_down_after_a_few_tries(): void
    {
        $this->enrolled($this->admin);

        $guess = fn (string $code) => $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com', 'code' => $code,
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ]);

        foreach (range(1, 5) as $try) {
            $guess(str_pad((string) $try, 6, '0', STR_PAD_LEFT))->assertSessionHasErrors('code');
        }

        // The sixth is refused before the code is even looked at — so even the
        // right one does not get through while the lockout holds.
        $secret = $this->admin->fresh()->two_factor_secret;

        $guess(Totp::at($secret))->assertSessionHasErrors('code');

        $this->assertTrue(
            Hash::check('a-strong-password-2026', $this->admin->fresh()->password),
            'the password did not change even with the right code',
        );
    }

    /** A password that got through means every other session is over. */
    public function test_recovering_ends_the_sessions_that_were_open(): void
    {
        $secret = $this->enrolled($this->admin);

        $this->admin->forceFill(['remember_token' => 'a-token-from-somewhere-else'])->save();

        $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => Totp::at($secret),
            'password' => 'a-brand-new-password-2026',
            'password_confirmation' => 'a-brand-new-password-2026',
        ])->assertRedirect(route('login'));

        $this->assertNull($this->admin->fresh()->remember_token);
    }

    /** The new password still has to be a real one. */
    public function test_the_new_password_still_has_to_pass_the_rules(): void
    {
        $secret = $this->enrolled($this->admin);

        $this->post(route('password.recover.update'), [
            'email' => 'admin@example.com',
            'code' => Totp::at($secret),
            'password' => '123',
            'password_confirmation' => '123',
        ])->assertSessionHasErrors('password');
    }

    /** The link is on the sign-in screen, where somebody locked out will look. */
    public function test_the_sign_in_screen_offers_it(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.recover'), false);
    }

    // =====================================================================
    // The lost phone, and the admin
    // =====================================================================

    public function test_an_admin_can_clear_a_lost_phone(): void
    {
        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $this->enrolled($staff);

        $this->actingAs($this->admin)
            ->delete(route('users.authenticator.destroy', $staff))
            ->assertSessionHasNoErrors();

        $this->assertFalse($staff->fresh()->hasAuthenticator());
    }

    public function test_staff_cannot_clear_anybody_elses(): void
    {
        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $this->enrolled($this->admin);

        $this->actingAs($staff)
            ->delete(route('users.authenticator.destroy', $this->admin))
            ->assertForbidden();

        $this->assertTrue($this->admin->fresh()->hasAuthenticator());
    }

    // =====================================================================
    // The last resort
    // =====================================================================

    /** For the morning when nobody at all can sign in. */
    public function test_the_console_can_set_a_password_when_nobody_can_sign_in(): void
    {
        $this->artisan('user:password', [
            'email' => 'admin@example.com',
            '--password' => 'set-from-the-server-2026',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('set-from-the-server-2026', $this->admin->fresh()->password));
    }

    public function test_the_console_can_clear_an_authenticator_too(): void
    {
        $this->enrolled($this->admin);

        $this->artisan('user:password', [
            'email' => 'admin@example.com',
            '--password' => 'set-from-the-server-2026',
            '--clear-authenticator' => true,
        ])->assertSuccessful();

        $this->assertFalse($this->admin->fresh()->hasAuthenticator());
    }

    public function test_the_console_says_which_accounts_exist_when_the_email_is_wrong(): void
    {
        $this->artisan('user:password', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('admin@example.com')
            ->assertFailed();
    }
}
