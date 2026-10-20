<?php

namespace App\Console\Commands;

use App\Support\Sections;
use Illuminate\Console\Command;

/**
 * Which sections this shop has, and what would stop one being switched off —
 * PROJECT_DOC Section 8h.
 *
 * `--json` is for Soran Panel, which runs this through the shared codebase with
 * SHOP_HOME set, before and after it rewrites the two lines in .env:
 *
 *     php /home/soransto/shops/hamza/artisan shop:sections --json
 *
 * It reads and never writes. The panel owns .env and writes the lines; this is
 * the shop's own answer, so the panel never has to guess from the shop's tables.
 *
 * ⚠️ **A name it does not know is reported, never ignored.** `repair` for
 * `repairs` switches nothing off, and a shop that was meant to lose a section
 * and did not is a fault nobody notices.
 *
 * ⚠️ **"Could not ask" is not "nothing holds it"** — see Sections::report().
 */
class ShopSections extends Command
{
    protected $signature = 'shop:sections {--json : Print the answer as JSON, for the panel}';

    protected $description = 'Say which sections this shop has, and what would stop one being switched off';

    public function handle(): int
    {
        $report = Sections::report();

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Zero whatever the answer. The caller asked a question and got
            // one; anything wrong with the answer is in the answer.
            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($report['sections'] as $row) {
            $state = $row['on'] ? '<fg=green>on</>' : '<fg=yellow>off</>';
            $why = $row['why'] === 'default' ? '' : ' ('.$row['why'].')';
            $testing = $row['testing'] ? ' <fg=cyan>testing</>' : '';

            $this->components->twoColumnDetail($row['name'].$testing, $state.$why);

            if ($row['holds'] !== null) {
                $this->line('  <fg=gray>Cannot be switched off: '.$row['holds'].'</>');
            }
        }

        if (! $report['holds_checked']) {
            $this->newLine();
            $this->components->warn('The database did not answer, so nothing above says what holds a section.');
        }

        foreach ($report['unknown'] as $name) {
            $this->newLine();
            $this->components->error("[{$name}] is in .env but is not a section. Nothing was switched by it.");
        }

        $this->newLine();

        return $report['unknown'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
