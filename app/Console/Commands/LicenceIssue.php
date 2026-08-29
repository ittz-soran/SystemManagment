<?php

namespace App\Console\Commands;

use App\Services\Licence;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sign one shop's licence. On the seller's machine, with the private key.
 *
 * Nothing here ever runs on a shop's server: the private key is not there, and
 * must not be.
 */
class LicenceIssue extends Command
{
    protected $signature = 'licence:issue
                            {shop : The shop’s name, as it should read on their screen}
                            {--host= : The domain it is valid on. Omit for a licence that runs anywhere.}
                            {--months=1 : How many months from today}
                            {--until= : An exact end date (YYYY-MM-DD), instead of --months}
                            {--forever : No end date at all, for a copy sold outright}
                            {--key= : Path to the private key file. Prompted for if omitted.}';

    protected $description = 'Sign a licence for one shop (seller only — needs the private key)';

    public function handle(): int
    {
        $path = $this->option('key') ?: $this->ask('Path to your private key file');

        if (! is_file($path)) {
            $this->error("No file at [{$path}].");

            return self::FAILURE;
        }

        $expires = match (true) {
            (bool) $this->option('forever') => null,
            filled($this->option('until')) => Carbon::parse($this->option('until'))->toDateString(),
            default => now()->addMonths((int) $this->option('months'))->toDateString(),
        };

        $payload = [
            'id' => strtoupper(Str::random(4).'-'.Str::random(4)),
            'shop' => $this->argument('shop'),
            'host' => $this->option('host') ?: null,
            'issued' => now()->toDateString(),
            'expires' => $expires,
        ];

        try {
            $licence = Licence::sign($payload, file_get_contents($path));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Shop', $payload['shop']);
        $this->components->twoColumnDetail('Domain', $payload['host'] ?? 'anywhere');
        $this->components->twoColumnDetail('Valid until', $expires ?? 'no end date');
        $this->components->twoColumnDetail('Reference', $payload['id']);

        $this->newLine();
        $this->line('Put this line in their .env, then run `php artisan config:clear`:');
        $this->newLine();
        $this->line('LICENCE_KEY='.$licence);
        $this->newLine();

        return self::SUCCESS;
    }
}
