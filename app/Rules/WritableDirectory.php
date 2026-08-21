<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A folder the app can actually write a backup into.
 *
 * Checked when the setting is saved rather than when the backup runs, because a
 * typo discovered at 02:15 by a cron job nobody is watching is a backup that
 * quietly never happened.
 */
class WritableDirectory implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $path = (string) $value;

        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            $fail(__('There is no folder at :path, and it could not be created.', ['path' => $path]));

            return;
        }

        if (! is_writable($path)) {
            $fail(__('The folder :path exists but cannot be written to.', ['path' => $path]));
        }
    }
}
