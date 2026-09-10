<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

/**
 * A secret written with a key the shop no longer has.
 *
 * This took a week of Soran's time and six wrong answers of mine, and the
 * shape of it is worth writing down: his shop's APP_KEY changed, so the
 * authenticator secret in the users table could no longer be decrypted — and
 * Eloquent decrypts every cast attribute while working out what is dirty.
 *
 * So **saving a user for any reason at all** raised
 * `DecryptException: The MAC is invalid`. Changing the interface language.
 * Changing the theme. Saving a preference. Logging out, because Laravel cycles
 * the remember token. Four unrelated screens, one cause, and every other part
 * of the shop perfectly healthy — which is exactly why it read as four
 * separate faults.
 *
 * A secret encrypted with a key nobody has is not a secret, it is bytes. There
 * is nothing to recover and nothing to protect. It reads as absent, the owner
 * enrols their phone again, and the shop keeps working.
 */
class LostEncryptionKeyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAnEnrolledPhone(): User
    {
        $user = User::create([
            'name' => 'Soran', 'email' => 'soran@shop.iq',
            'password' => 'correct-horse-battery', 'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /** Everything written under the old key becomes unreadable at once. */
    private function theKeyChanges(): void
    {
        $key = Encrypter::generateKey(Config::get('app.cipher'));

        // The `base64:` prefix matters: without it Laravel takes the encoded
        // string as the raw key and refuses it for being the wrong length,
        // which fails as a broken test rather than as a changed key.
        Config::set('app.key', 'base64:'.base64_encode($key));

        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    }

    public function test_the_secret_is_readable_while_the_key_is_the_same(): void
    {
        $user = $this->userWithAnEnrolledPhone();

        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->two_factor_secret);
        $this->assertTrue($user->fresh()->hasAuthenticator());
    }

    /**
     * The one that mattered: saving for an unrelated reason must not throw.
     *
     * Reproduced against the old `encrypted` cast before this was written —
     * `forceFill(['language' => 'ckb'])->save()` raised DecryptException,
     * which is the 500 Soran met on four different screens.
     */
    public function test_changing_a_preference_still_works_after_the_key_is_lost(): void
    {
        $this->userWithAnEnrolledPhone();

        $this->theKeyChanges();

        $user = User::first();

        $user->forceFill(['language' => 'ckb'])->save();

        $this->assertSame('ckb', $user->fresh()->language);
    }

    /** Logging out saves the user too, by cycling the remember token. */
    public function test_logging_out_still_works_after_the_key_is_lost(): void
    {
        $this->userWithAnEnrolledPhone();

        $this->theKeyChanges();

        $this->actingAs(User::first())
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    /** What cannot be read is absent, not an exception. */
    public function test_an_unreadable_secret_reads_as_nothing(): void
    {
        $this->userWithAnEnrolledPhone();

        $this->theKeyChanges();

        $user = User::first();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    /**
     * And the owner is not locked out by it.
     *
     * If this said yes, the sign-in screen would demand a code that can never
     * be right — somebody shut out of their own shop over a secret nobody in
     * the world can use.
     */
    public function test_the_owner_is_not_asked_for_a_code_that_cannot_exist(): void
    {
        $this->userWithAnEnrolledPhone();

        $this->theKeyChanges();

        $this->assertFalse(User::first()->hasAuthenticator());
    }

    /** The authenticator screen opens, and offers a fresh enrolment. */
    public function test_the_authenticator_screen_opens_and_offers_a_new_start(): void
    {
        $this->userWithAnEnrolledPhone();

        $this->theKeyChanges();

        $this->actingAs(User::first())
            ->get(route('authenticator.show'))
            ->assertOk();
    }

    /** Enrolling again works, and is readable under the new key. */
    public function test_the_phone_can_be_enrolled_again(): void
    {
        $this->userWithAnEnrolledPhone();

        $this->theKeyChanges();

        $user = User::first();

        $user->forceFill(['two_factor_secret' => 'NEWSECRET234567'])->save();

        $this->assertSame('NEWSECRET234567', $user->fresh()->two_factor_secret);
    }
}
