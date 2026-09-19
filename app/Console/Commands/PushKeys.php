<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Make the one pair of keys a shop needs to buzz a phone.
 *
 * ⚠️ **The private key never leaves the server**, the same rule as the licence
 * key. It is printed once, pasted into `.env`, and nothing else ever shows it.
 */
class PushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Make the VAPID keys this shop needs to send notifications to phones';

    public function handle(): int
    {
        if (config('push.public_key') && ! $this->option('no-interaction')) {
            $this->warn(__('This shop already has keys.'));
            $this->line(__('Replacing them makes every phone that has already agreed stop receiving. Each one has to turn notifications on again.'));

            if (! $this->confirm(__('Make new keys anyway?'), false)) {
                return self::SUCCESS;
            }
        }

        $keys = VAPID::createVapidKeys();

        $this->newLine();
        $this->info('Add these three lines to .env, then run: php artisan config:clear');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@yourshop.com');
        $this->newLine();
        $this->warn('Keep the private key on the server. It is not needed anywhere else.');

        return self::SUCCESS;
    }
}
