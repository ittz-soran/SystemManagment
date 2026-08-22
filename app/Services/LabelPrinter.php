<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Send a label straight to the printer, with no print dialog in between.
 *
 * Only possible because the application runs on the same machine as the
 * printer: a browser cannot choose a printer — window.print() hands the job to
 * the operating system and the person picks — but PHP on that machine can open
 * a shared printer and write to it.
 *
 * So there are two routes, and the browser one is not a poor relation. It needs
 * no setup, works from a phone on the counter, and is the fallback whenever
 * this one is not configured or not reachable.
 */
class LabelPrinter
{
    /** Where the server should send raw TSPL, or null when nobody has set one. */
    public function target(): ?string
    {
        $printer = setting('label_printer', config('labels.printer'));

        return is_string($printer) && trim($printer) !== '' ? trim($printer) : null;
    }

    public function isConfigured(): bool
    {
        return $this->target() !== null;
    }

    /**
     * The printers this machine knows about, for the Settings dropdown.
     *
     * Windows only, and best-effort: a machine that cannot be asked simply
     * gets the free-text box, which is why nothing here throws.
     *
     * @return array<string, string> share path => label
     */
    public function available(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [];
        }

        try {
            $process = new Process([
                'wmic', 'printer', 'get', 'Name,ShareName,Shared', '/format:csv',
            ]);

            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            return $this->parseWmic($process->getOutput());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseWmic(string $output): array
    {
        $printers = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $columns = str_getcsv(trim($line));

            // Node,Name,ShareName,Shared
            if (count($columns) < 4 || $columns[1] === 'Name' || $columns[1] === '') {
                continue;
            }

            [$node, $name, $share, $shared] = $columns;

            // Raw printing goes through a share, so an unshared printer is
            // listed with the reason rather than silently left out.
            $printers[$share !== '' ? '\\\\localhost\\'.$share : ''] = $share !== ''
                ? $name
                : $name.' — '.__('not shared');
        }

        unset($printers['']);

        return $printers;
    }

    /**
     * Write raw bytes to the printer.
     *
     * A shared printer on Windows is a file you can copy to, which is the
     * oldest and most reliable way to reach one without a driver in the middle.
     */
    public function send(string $payload): void
    {
        $target = $this->target()
            ?? throw new RuntimeException(__('No printer is set up for direct printing. Choose one on the Settings page, or print through the browser instead.'));

        DIRECTORY_SEPARATOR === '\\'
            ? $this->sendWindows($target, $payload)
            : $this->sendPosix($target, $payload);
    }

    private function sendWindows(string $target, string $payload): void
    {
        $handle = @fopen($target, 'wb');

        if ($handle === false) {
            throw new RuntimeException(__('Could not reach :printer. Check that the printer is shared under that exact name and switched on.', [
                'printer' => $target,
            ]));
        }

        try {
            if (@fwrite($handle, $payload) === false) {
                throw new RuntimeException(__('Could not reach :printer. Check that the printer is shared under that exact name and switched on.', [
                    'printer' => $target,
                ]));
            }
        } finally {
            fclose($handle);
        }
    }

    /** For a Linux till: lp takes the same raw bytes. */
    private function sendPosix(string $target, string $payload): void
    {
        $process = new Process(['lp', '-d', $target, '-o', 'raw']);
        $process->setInput($payload);
        $process->setTimeout((float) config('labels.timeout', 20));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(__('Could not reach :printer. :error', [
                'printer' => $target,
                'error' => trim($process->getErrorOutput()) ?: __('lp could not be run.'),
            ]));
        }
    }
}
