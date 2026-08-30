<?php

namespace App\Console\Commands;

use App\Support\OpenSslConfig;
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
    protected $signature = 'licence:keys
                            {--force : Yes, replace the pair and invalidate every licence already issued}
                            {--config= : Path to openssl.cnf, if this machine cannot find its own}';

    protected $description = 'Make the seller keypair that signs licences (run once, on your own machine)';

    public function handle(): int
    {
        if (config('licence.public_key') && ! $this->option('force')) {
            $this->error('This copy already carries a public key.');
            $this->line('Making another one stops every licence you have already issued from verifying.');
            $this->line('If that is really what you want: php artisan licence:keys --force');

            return self::FAILURE;
        }

        /*
         * Windows PHP ships without a compiled-in path to openssl.cnf, so
         * making a pair fails there with "error:80000003:system library::No
         * such process" — which is a long way of saying "no such file". XAMPP
         * does ship the file, twice, and never says where.
         */
        $config = OpenSslConfig::find($this->option('config'));

        $options = array_filter([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $config,
        ], fn ($value) => $value !== null);

        // Clear anything left in OpenSSL's error queue, so what is reported
        // below is this call's fault and not a previous one's.
        OpenSslConfig::errors();

        $resource = openssl_pkey_new($options);

        if ($resource === false) {
            $this->cannotMakeAKey($config);

            return self::FAILURE;
        }

        if (! openssl_pkey_export($resource, $private, null, $options)) {
            $this->cannotMakeAKey($config);

            return self::FAILURE;
        }
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

    /**
     * Say what went wrong in the words that lead somewhere.
     *
     * The same shape as the missing-mysqldump message: name the file, name
     * where XAMPP keeps it, and give the two ways to point at it. An OpenSSL
     * error code on its own has never helped anybody.
     */
    private function cannotMakeAKey(?string $config): void
    {
        $this->error('OpenSSL would not make a key: '.OpenSslConfig::errors());
        $this->newLine();

        if ($config) {
            $this->line('It was told to use this configuration file:');
            $this->line('  '.$config);
            $this->newLine();
            $this->line('If that file is not really there, or is not really openssl.cnf, point at the right one:');
        } else {
            $this->line('This almost always means OpenSSL could not find its openssl.cnf.');
            $this->line('PHP for Windows ships without a path to it, and XAMPP keeps a copy in both of these:');
            $this->newLine();
            $this->line('  C:\\xampp\\apache\\conf\\openssl.cnf');
            $this->line('  C:\\xampp\\php\\extras\\ssl\\openssl.cnf');
            $this->newLine();
            $this->line('Point at whichever exists:');
        }

        $this->newLine();
        $this->line('  php artisan licence:keys --config="C:\\xampp\\apache\\conf\\openssl.cnf"');
        $this->newLine();
        $this->line('Or set it once for the whole machine, as OPENSSL_CONF, and open a new terminal.');
        $this->newLine();
        $this->comment('Only making the pair needs this file. Signing licences and checking them do not,');
        $this->comment('so no shop’s server ever needs it — this is a one-off on your own machine.');
    }
}
