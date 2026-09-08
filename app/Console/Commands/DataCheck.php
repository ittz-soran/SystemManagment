<?php

namespace App\Console\Commands;

use App\Services\DataIntegrityService;
use Illuminate\Console\Command;

/**
 * Section 10b's assertions, run against this shop's real data, from a terminal.
 *
 * The same service the Data check screen uses, and the same rule: it reports
 * and it does not touch. A command that silently "fixed" a contradiction would
 * destroy the evidence of what went wrong, and the difference between a cache
 * to rebuild and two records that cannot both be right is exactly the judgement
 * a person has to make.
 *
 * It exists because the panel needs it (PANEL_DOC Section 8): the panel runs a
 * shop's own commands through the shared codebase with SHOP_HOME set, so the
 * answer is the shop's own rather than the panel's opinion of the shop. Until
 * now the check was reachable only through a browser, signed in as that shop's
 * administrator, which the panel is not and should not become.
 *
 * The exit code is the useful half for a script: 0 when nothing serious was
 * found, 1 when something was.
 */
class DataCheck extends Command
{
    protected $signature = 'data:check {--json : Print the findings as JSON instead of a table}';

    protected $description = 'Check this shop’s data against the Section 10b assertions';

    public function handle(DataIntegrityService $integrity): int
    {
        $found = $integrity->run();

        $this->option('json')
            ? $this->printJson($found)
            : $this->printTable($found);

        return $found['serious'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $found
     */
    private function printJson(array $found): void
    {
        $this->output->writeln(json_encode([
            'total' => count($found['checks']),
            'passed' => $found['ok'],
            'serious' => $found['serious'],
            'rebuildable' => $found['rebuildable'],
            'unavailable' => $found['unavailable'],
            'rows' => $found['rows'],
            'ran_for' => $found['ran_for'],

            // Only what did not pass. The whole set is a page of prose; what a
            // caller needs is the short list of things to look at.
            'failing' => array_values(array_map(
                fn ($check) => [
                    'key' => $check['key'],
                    'title' => $check['title'],
                    'severity' => $check['severity'],
                    'examined' => $check['examined'],
                    'failed' => $check['failed'],
                ],
                array_filter($found['checks'], fn ($check) => $check['severity'] !== DataIntegrityService::OK),
            )),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $found
     */
    private function printTable(array $found): void
    {
        $this->newLine();

        foreach ($found['checks'] as $check) {
            $this->components->twoColumnDetail(
                $check['title'],
                match ($check['severity']) {
                    DataIntegrityService::OK => '<fg=green>agrees</>',
                    DataIntegrityService::REBUILDABLE => '<fg=yellow>'.$check['failed'].' to rebuild</>',
                    DataIntegrityService::UNAVAILABLE => '<fg=gray>could not run</>',
                    default => '<fg=red>'.$check['failed'].' wrong</>',
                },
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Checked',
            sprintf('%d of %d agree, over %s rows, in %ss',
                $found['ok'], count($found['checks']), number_format($found['rows']), $found['ran_for']),
        );

        if ($found['serious'] > 0) {
            $this->newLine();
            $this->components->error($found['serious'].' need a person to look at them. Nothing has been changed.');
        }

        $this->newLine();
    }
}
