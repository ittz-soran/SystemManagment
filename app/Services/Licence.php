<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether this copy is still paid for, and still the copy it was sold as.
 *
 * The system is sold monthly, so something has to notice when it stops being
 * paid for — and the seller cannot be the thing that notices, or every lapsed
 * month is a phone call he has to remember to make.
 *
 * A signed licence, checked offline. The seller holds a private key; every copy
 * ships with the public half. A licence is a small piece of JSON — who it is
 * for, which domain, until when — with a signature over it. The shop can read
 * it. The shop cannot change a word of it without the signature failing, and
 * cannot write a new one at all.
 *
 * Offline on purpose. A licence that phoned home would stop the shop trading
 * every time the fibre was cut, which is the same week we added a light to the
 * topbar because the fibre gets cut. It also means a shop with no internet at
 * all still works, which is most of the point of this system.
 *
 * What this is honestly worth: it makes not paying take a deliberate act, and
 * it makes copying the folder to a second shop not work. Somebody with the
 * source and the server can delete the check — no licence written in PHP can
 * stop that, and one that claimed to would only be lying about it.
 */
final class Licence
{
    /** No public key configured: this copy was never sold, and none of this applies. */
    public const UNLICENSED = 'unlicensed';

    /** Signed, in date, on the right domain. */
    public const VALID = 'valid';

    /** In date, but not for much longer. */
    public const EXPIRING = 'expiring';

    /** Past the date, inside the grace days. Everything still works. */
    public const GRACE = 'grace';

    /** Past the date and past the grace. Read-only. */
    public const EXPIRED = 'expired';

    /** Nothing in .env at all, on a copy that requires one. */
    public const MISSING = 'missing';

    /** Present but not signed by the key this copy ships with. */
    public const INVALID = 'invalid';

    /** Signed, but issued for another shop's domain. */
    public const WRONG_HOST = 'wrong_host';

    private const CACHE_KEY = 'licence.state';

    /**
     * The whole answer, held for a minute.
     *
     * Verifying is one RSA check — cheap, but this runs on every page, and a
     * till does not need to redo the same sum four hundred times an hour.
     *
     * @return array{
     *     state: string, shop: string|null, host: string|null,
     *     expires: Carbon|null, days_left: int|null, id: string|null
     * }
     */
    public function check(): array
    {
        $found = Cache::remember(self::CACHE_KEY, 60, fn () => $this->verify());

        /*
         * The date is held as a string and made a Carbon on the way out.
         *
         * Nothing but scalars goes into the cache. A Carbon object stored here
         * came back from the file driver as an incomplete class and took the
         * whole settings page down with "tried to call a method on an
         * incomplete object" — and the suite never saw it, because the array
         * driver tests run on hands objects back without serialising them. The
         * cache is the wrong place for anything that has to be reconstructed.
         */
        $found['expires'] = $found['expires'] === null ? null : Carbon::parse($found['expires']);

        return $found;
    }

    public function state(): string
    {
        return $this->check()['state'];
    }

    /** Whether this copy is licensed at all — false means the feature is off. */
    public function isRequired(): bool
    {
        return trim((string) config('licence.public_key')) !== '';
    }

    /**
     * Whether the shop may still write.
     *
     * Everything except a licence that has run out of road: an unlicensed copy,
     * a valid one, one about to expire and one inside its grace days all trade
     * normally. Only `expired`, `missing`, `invalid` and `wrong_host` stop it.
     */
    public function allowsWriting(): bool
    {
        return in_array($this->state(), [
            self::UNLICENSED, self::VALID, self::EXPIRING, self::GRACE,
        ], true);
    }

    /** Re-read now. Called when a new key is put in place. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Sign a payload. The seller's side, never the shop's.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $privateKey): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new \RuntimeException('That private key could not be read.');
        }

        openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);

        return self::encode($body).'.'.self::encode($signature);
    }

    /**
     * A public key as OpenSSL insists on seeing it.
     *
     * `licence:keys` prints a full PEM, header and footer included, and OpenSSL
     * will not read one without them — but what a person copies off a screen is
     * the interesting-looking middle. Soran pasted exactly that into
     * config/licence.php, and it would have failed as "this licence cannot be
     * read" on the first licence he issued, which points at the licence rather
     * than at the key and would have sent him looking in the wrong place.
     *
     * A bare base64 body is unambiguous here, so it is simply wrapped rather
     * than refused. Anything already carrying its header is left exactly as it
     * is.
     */
    private static function armour(string $key): string
    {
        $key = trim($key);

        if ($key === '' || str_contains($key, 'BEGIN')) {
            return $key;
        }

        // Re-wrapped at 64 characters, in case it arrived as one long line.
        $body = chunk_split(preg_replace('/\s+/', '', $key), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n".$body.'-----END PUBLIC KEY-----';
    }

    /** @return array<string, mixed> */
    private function verify(): array
    {
        $blank = ['state' => self::UNLICENSED, 'shop' => null, 'host' => null,
            'expires' => null, 'days_left' => null, 'id' => null];

        if (! $this->isRequired()) {
            return $blank;
        }

        $key = trim((string) config('licence.key'));

        if ($key === '') {
            return [...$blank, 'state' => self::MISSING];
        }

        $parts = explode('.', $key);

        if (count($parts) !== 2) {
            return [...$blank, 'state' => self::INVALID];
        }

        $body = self::decode($parts[0]);
        $signature = self::decode($parts[1]);

        if ($body === false || $signature === false) {
            return [...$blank, 'state' => self::INVALID];
        }

        $public = openssl_pkey_get_public(self::armour((string) config('licence.public_key')));

        // A signature that does not verify is the whole point: the shop can
        // read this string, and can change nothing in it.
        if ($public === false || openssl_verify($body, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
            return [...$blank, 'state' => self::INVALID];
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return [...$blank, 'state' => self::INVALID];
        }

        $found = [
            'shop' => $payload['shop'] ?? null,
            'host' => $payload['host'] ?? null,
            'id' => $payload['id'] ?? null,
            // A string, not a Carbon: see check(). Kept at the end of the day,
            // because a licence good until the 29th is good all of the 29th.
            'expires' => isset($payload['expires'])
                ? Carbon::parse($payload['expires'])->endOfDay()->toIso8601String()
                : null,
            'days_left' => null,
        ];

        if (! $this->hostMatches($found['host'])) {
            return [...$found, 'state' => self::WRONG_HOST, 'days_left' => null];
        }

        // No date at all is a licence sold outright rather than monthly.
        if ($found['expires'] === null) {
            return [...$found, 'state' => self::VALID];
        }

        /*
         * Whole days, counted the way a person counts them.
         *
         * From the start of today to the expiry date: the 29th with a licence
         * ending on the 11th is thirteen days, not thirteen-and-a-half rounded
         * up to fourteen. Measuring from the current moment to the end of the
         * last day made "13 days left" read as 14 and pushed the warning a day
         * later than the setting says.
         */
        $daysLeft = (int) now()->startOfDay()->diffInDays(Carbon::parse($found['expires'])->startOfDay(), false);
        $found['days_left'] = $daysLeft;

        $state = match (true) {
            $daysLeft >= (int) config('licence.warn_days', 14) => self::VALID,
            $daysLeft >= 0 => self::EXPIRING,
            $daysLeft >= -(int) config('licence.grace_days', 7) => self::GRACE,
            default => self::EXPIRED,
        };

        return [...$found, 'state' => $state];
    }

    /**
     * Whether this licence was issued for the domain it is running on.
     *
     * What stops a folder being copied to a second shop. `www.` is ignored
     * because it is the same shop, and a licence with no host named is one the
     * seller deliberately left portable — for a shop that has not settled on a
     * domain, or for his own machine.
     */
    private function hostMatches(?string $licensed): bool
    {
        if (blank($licensed)) {
            return true;
        }

        /*
         * The domain this copy is actually answering on — but only when
         * somebody is actually asking.
         *
         * In a console command there is no request, and Laravel invents one
         * that says `localhost`, which is not where the shop lives. Reading
         * that as the running host made every real licence look like it had
         * been issued for somewhere else, so `licence:show` and the scheduler
         * both said a perfectly good licence was wrong. APP_URL is the answer
         * off the web, and it is the same value the shop's own printed
         * invoices are built from.
         */
        $running = app()->runningInConsole()
            ? (parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.url'))
            : request()->getHost();

        $strip = fn (string $host) => preg_replace('/^www\./i', '', mb_strtolower(trim($host)));

        return $strip($licensed) === $strip((string) $running);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
