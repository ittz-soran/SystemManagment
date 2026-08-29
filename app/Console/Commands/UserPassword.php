<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The last way in, for whoever runs the server.
 *
 * Everything else — the authenticator, an admin resetting somebody's password —
 * assumes at least one person can still sign in. This is for the morning when
 * nobody can: the only admin forgot their password, their phone is gone and the
 * recovery codes with it.
 *
 * It needs shell access to the server, which the shop does not have and the
 * person who sold them the system does. That is exactly the right shape for a
 * last resort.
 */
class UserPassword extends Command
{
    protected $signature = 'user:password
                            {email : Whose password to set}
                            {--password= : The new password. Omit it and one is generated and printed once.}
                            {--clear-authenticator : Also remove their authenticator app, for a phone that is gone}';

    protected $description = 'Set a password from the server, when nobody can sign in to do it';

    public function handle(): int
    {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No account with the email [{$this->argument('email')}].");

            $this->newLine();
            $this->line('The accounts on this system:');

            foreach (User::withoutGlobalScopes()->orderBy('id')->get() as $each) {
                $this->line(sprintf(
                    '  %-34s %-6s %s',
                    $each->email,
                    $each->role,
                    $each->hasAuthenticator() ? 'authenticator on' : '',
                ));
            }

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(16);

        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        if ($this->option('clear-authenticator')) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        // Attributed to the account itself, because a console has no signed-in
        // user and an unattributable row would be worse than a slightly odd
        // one: what matters is that the change is in the history at all.
        app(ActivityLogger::class)->log(
            action: 'update',
            module: 'users',
            recordId: $user->id,
            description: __('Password set from the server console'),
            user: $user,
        );

        $this->newLine();
        $this->info("Password set for {$user->email}.");

        if (! $this->option('password')) {
            $this->line('  Password: '.$password);
            $this->comment('  Write it down now — it is not shown again. Change it at /profile.');
        }

        if ($this->option('clear-authenticator')) {
            $this->comment('  Their authenticator was removed. They can set it up again from their own preferences.');
        }

        return self::SUCCESS;
    }
}
