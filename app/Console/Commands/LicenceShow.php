<?php

namespace App\Console\Commands;

use App\Services\Licence;
use Illuminate\Console\Command;

/**
 * What this copy thinks of its own licence.
 *
 * For the telephone call that starts "it says my licence is wrong" — this
 * answers, in one line, whether the string is missing, unsigned, for another
 * domain, or simply out of date.
 *
 * `--json` is for the panel (PANEL_DOC Section 8): it runs this command through
 * the shared codebase with SHOP_HOME set, to record what the shop itself thinks
 * as a cross-check against what the panel believes. The prose above cannot be
 * read by a machine — it is coloured, translated and deliberately a sentence
 * rather than a state — so the state is offered raw beside it. Neither format
 * computes anything the other does not; both print the same Licence::check().
 */
class LicenceShow extends Command
{
    protected $signature = 'licence:show {--json : Print the answer as JSON instead of prose}';

    protected $description = 'Say what this copy makes of its licence';

    public function handle(Licence $licence): int
    {
        $found = $licence->check();

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'state' => $found['state'],
                'shop' => $found['shop'],
                'host' => $found['host'],
                'expires' => $found['expires']?->toDateString(),
                'days_left' => $found['days_left'],
                'id' => $found['id'],
                'allows_writing' => $licence->allowsWriting(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Zero whatever the licence says. The caller asked a question and
            // got an answer; a non-zero exit here would read to a script as
            // "the command failed" rather than "the licence has run out".
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('State', match ($found['state']) {
            Licence::UNLICENSED => '<fg=gray>not a sold copy — licensing is off</>',
            Licence::VALID => '<fg=green>valid</>',
            Licence::EXPIRING => '<fg=yellow>valid, ending soon</>',
            Licence::GRACE => '<fg=yellow>past its date, inside the grace days</>',
            Licence::EXPIRED => '<fg=red>expired — the shop is read-only</>',
            Licence::MISSING => '<fg=red>no LICENCE_KEY in .env</>',
            Licence::INVALID => '<fg=red>not signed by this copy’s key</>',
            Licence::WRONG_HOST => '<fg=red>issued for another domain</>',
            default => $found['state'],
        });

        $this->components->twoColumnDetail('Shop', $found['shop'] ?? '—');
        $this->components->twoColumnDetail('Domain', $found['host'] ?? 'anywhere');
        $this->components->twoColumnDetail('Running on', request()->getHost() ?: config('app.url'));
        $this->components->twoColumnDetail('Valid until', $found['expires']?->toDateString() ?? 'no end date');

        if ($found['days_left'] !== null) {
            $this->components->twoColumnDetail('Days left', (string) $found['days_left']);
        }

        $this->components->twoColumnDetail('Reference', $found['id'] ?? '—');
        $this->newLine();

        return $licence->allowsWriting() ? self::SUCCESS : self::FAILURE;
    }
}
