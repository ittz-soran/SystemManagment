<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted at rest, and forgiving when the key that wrote it is gone.
 *
 * Laravel's own `encrypted` cast throws `DecryptException: The MAC is invalid`
 * when APP_KEY has changed since the value was written. That is correct and it
 * is far too loud: Eloquent decrypts every cast attribute while working out
 * what is dirty, so **saving a user for any reason at all** decrypts their
 * authenticator secret. Changing the interface language took Soran's shop down
 * for a week, and so did changing the theme, saving a preference, and logging
 * out — because each of those saves the user row.
 *
 * A secret encrypted with a key nobody has is not a secret, it is bytes. There
 * is nothing to recover and nothing to protect, so it reads as absent and the
 * owner enrols their phone again. What must not happen is the rest of the shop
 * falling over around it.
 *
 * `null` means "there is nothing here" and "there is something here that can
 * never be read again" alike, and they are the same thing to every caller.
 */
class Unreadable implements CastsAttributes
{
    /** @param  'string'|'array'  $shape */
    public function __construct(private string $shape = 'string') {}

    public function get($model, string $key, $value, array $attributes): string|array|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        if ($this->shape !== 'array') {
            return $plain;
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(
            $this->shape === 'array' ? json_encode(array_values((array) $value)) : (string) $value,
        );
    }
}
