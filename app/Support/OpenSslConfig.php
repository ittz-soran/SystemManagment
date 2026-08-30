<?php

namespace App\Support;

/**
 * Where openssl.cnf actually lives when OpenSSL cannot find it itself.
 *
 * Making a keypair is the one thing in this system that needs OpenSSL's own
 * configuration file, and on Windows it usually cannot find it:
 *
 *     OpenSSL would not make a key.
 *     error:80000003:system library::No such process
 *
 * Which is a very long way of saying "no such file". PHP for Windows ships
 * without a compiled-in path to openssl.cnf, so unless OPENSSL_CONF is set the
 * library looks somewhere that does not exist and reports the miss in the least
 * helpful words available. XAMPP does ship the file — twice, in fact — it just
 * never tells anybody where.
 *
 * Reading, signing and verifying need none of this. Only generating a pair
 * does, which is a thing the seller does once on their own machine and no shop
 * ever does at all.
 */
final class OpenSslConfig
{
    /**
     * The usual places, in the order worth trying.
     *
     * Forward slashes throughout, for the same reason BackupService uses them:
     * PHP's glob() on Windows does not handle backslashes, and Windows is happy
     * with either.
     *
     * @var list<string>
     */
    public const CANDIDATES = [
        // XAMPP ships it in both of these, and neither is on any path.
        'C:/xampp/apache/conf/openssl.cnf',
        'C:/xampp/php/extras/ssl/openssl.cnf',
        'C:/xampp/apache/bin/openssl.cnf',

        'C:/laragon/bin/apache/*/conf/openssl.cnf',
        'C:/laragon/etc/ssl/openssl.cnf',

        'C:/wamp64/bin/apache/*/conf/openssl.cnf',

        'C:/Program Files/OpenSSL-Win64/bin/openssl.cfg',
        'C:/Program Files/OpenSSL/bin/openssl.cfg',

        // Everywhere that is not Windows, where this is almost never needed.
        '/etc/ssl/openssl.cnf',
        '/usr/local/ssl/openssl.cnf',
        '/opt/homebrew/etc/openssl@3/openssl.cnf',
        '/usr/local/etc/openssl@3/openssl.cnf',
    ];

    /**
     * The file to hand OpenSSL, or null to let it find its own.
     *
     * An explicit path wins and is not second-guessed: somebody who typed one
     * wants it used, and a wrong one reported rather than quietly replaced.
     */
    public static function find(?string $explicit = null): ?string
    {
        if (filled($explicit)) {
            return $explicit;
        }

        // Already set for this machine, by whoever set it up. Believe them.
        $environment = getenv('OPENSSL_CONF') ?: null;

        if ($environment && is_file($environment)) {
            return $environment;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (str_contains($candidate, '*')) {
                foreach (glob($candidate) ?: [] as $match) {
                    if (is_file($match)) {
                        return $match;
                    }
                }

                continue;
            }

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Everything OpenSSL has to say, not just the first line.
     *
     * openssl_error_string() pops one error off a queue, so calling it once
     * reports the last thing that went wrong rather than the first — which is
     * usually the only one that explains anything.
     */
    public static function errors(): string
    {
        $errors = [];

        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return implode(' · ', array_reverse($errors)) ?: 'no reason given';
    }
}
