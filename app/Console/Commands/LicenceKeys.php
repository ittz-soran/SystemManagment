<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The seller's keypair. Run once, on the seller's own machine, and never again.
 *
 * The private half is the business: anybody holding it can issue themselves a
 * perpetual licence, so it must never reach a shop's server, a repository, or
 * a chat message. The public half is meant to be seen — it ships with every
 * copy and can only check signatures, never make them.
 *
 * Run it a second time and every licence already issued stops verifying, so it
 * says so before it does anything.
 */
class LicenceKeys extends Command
{
    protected $signature = 'licence:keys {--force : Yes, replace the pair and invalidate every licence already issued}';

    protected $description = 'Make the seller keypair that signs licences (run once, on your own machine)';

    public function handle(): int
    {
        if (config('licence.public_key') && ! $this->option('force')) {
            $this->error('This copy already carries a public key.');
            $this->line('Making another one stops every licence you have already issued from verifying.');
            $this->line('If that is really what you want: php artisan licence:keys --force');

            return self::FAILURE;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->error('OpenSSL would not make a key. '.openssl_error_string());

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $private);
        $public = openssl_pkey_get_details($resource)['key'];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow;options=bold>PRIVATE KEY</>', 'keep this, and only this, secret');
        $this->newLine();
        $this->line($private);

        $this->components->twoColumnDetail('<fg=green;options=bold>PUBLIC KEY</>', 'ships with every copy');
        $this->newLine();
        $this->line($public);

        $this->newLine();
        $this->components->bulletList([
            'Save the PRIVATE key somewhere only you can reach. Not in this repository, not on a shop’s server, not in a chat message. Losing it means you can never issue another licence for the copies already out there; leaking it means anybody can.',
            'Put the PUBLIC key in config/licence.php and commit it. That is what switches licensing on.',
            'Issue your first licence — to yourself, with no expiry — before you deploy, or your own install goes read-only.',
        ]);

        return self::SUCCESS;
    }
}
