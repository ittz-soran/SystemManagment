<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Getting back in without the password and without the post.
 *
 * Laravel's own forgotten-password flow emails a link, and MAIL_MAILER is `log`
 * on every install of this system — so that link has never left the building.
 * On the shared hosting these shops run on it will stay that way. Which was
 * survivable while the only user was the person who built it, and stopped being
 * survivable the moment the system was sold: an owner who forgets their
 * password has nobody to ask, and the whole shop's records are behind it.
 *
 * So: the email address, then six digits off the phone that was enrolled, then
 * a new password. Nothing has to be delivered anywhere.
 *
 * The code is what makes it safe, and six digits is a million guesses — so it
 * is rate limited hard, per account and per machine, and a lockout is measured
 * in minutes rather than seconds. Without that this is a doorway rather than a
 * door.
 */
class RecoverPasswordController extends Controller
{
    /** Five tries, then a minute of nothing. A million guesses is a long time at that rate. */
    private const TRIES = 5;

    private const LOCKOUT = 60;

    public function show(): View
    {
        return view('auth.recover');
    }

    /**
     * Everything in one step, because the shopkeeper standing at a locked
     * screen wants a way in rather than a wizard: who they are, the six digits
     * their phone is showing, and what the password should become.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->refuseIfTooManyTries($request, $data['email']);

        $user = User::withoutGlobalScopes()->where('email', $data['email'])->first();

        // One message for every way of being wrong, so this cannot be used to
        // ask which addresses have an account or which have an authenticator.
        if (! $user || ! $user->hasAuthenticator() || ! $this->accepts($user, $data['code'])) {
            RateLimiter::hit($this->key($request, $data['email']), self::LOCKOUT);

            throw ValidationException::withMessages([
                'code' => __('That did not work. Check the email address and the six digits the app is showing right now — or use one of the recovery codes instead.'),
            ]);
        }

        RateLimiter::clear($this->key($request, $data['email']));

        $user->forceFill(['password' => $data['password']])->save();

        // Every session this account had open, ended. A password reset that
        // leaves the old sessions signed in has not locked anybody out.
        $user->forceFill(['remember_token' => null])->save();

        app(ActivityLogger::class)->log(
            action: 'update',
            module: 'users',
            recordId: $user->id,
            description: __('Set a new password with the authenticator app'),
            user: $user,
        );

        return redirect()->route('login')
            ->with('success', __('The password is changed. Sign in with the new one.'));
    }

    /** The phone, or one of the eight codes written down when it was set up. */
    private function accepts(User $user, string $code): bool
    {
        if (Totp::check($user->two_factor_secret, $code)) {
            return true;
        }

        return $user->spendRecoveryCode($code);
    }

    private function refuseIfTooManyTries(Request $request, string $email): void
    {
        if (! RateLimiter::tooManyAttempts($this->key($request, $email), self::TRIES)) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => __('Too many tries. Wait :seconds seconds and start again.', [
                'seconds' => RateLimiter::availableIn($this->key($request, $email)),
            ]),
        ]);
    }

    /**
     * Counted per account and per machine together.
     *
     * Per account alone lets anybody lock the shop's owner out by guessing
     * badly on purpose; per machine alone lets a patient attacker work through
     * every account from one place. Both, and neither trick works.
     */
    private function key(Request $request, string $email): string
    {
        return 'recover|'.mb_strtolower($email).'|'.$request->ip();
    }
}
