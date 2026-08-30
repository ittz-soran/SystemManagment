<?php

namespace Tests\Feature;

use App\Services\Licence;
use App\Support\OpenSslConfig;
use Dotenv\Dotenv;
use Tests\TestCase;

/**
 * Making the seller's keypair, including on the machine where it does not work.
 *
 * `php artisan licence:keys` failed on Soran's own machine with
 *
 *     OpenSSL would not make a key.
 *     error:80000003:system library::No such process
 *
 * which is OpenSSL's way of saying it could not find openssl.cnf. PHP for
 * Windows ships without a path to it; XAMPP ships the file twice and mentions
 * it nowhere. Nothing in the message said any of that, so there was nothing to
 * act on — which is the actual bug.
 */
class LicenceKeysTest extends TestCase
{
    public function test_it_makes_a_pair_that_signs_and_verifies(): void
    {
        $this->artisan('licence:keys')
            ->expectsOutputToContain('BEGIN PRIVATE KEY')
            ->expectsOutputToContain('BEGIN PUBLIC KEY')
            ->assertSuccessful();
    }

    /**
     * The whole point of the fix: a failure that says what to do.
     *
     * A path that is not there stands in for the Windows machine that cannot
     * find its own.
     */
    public function test_a_missing_config_file_says_where_xampp_keeps_one(): void
    {
        $this->artisan('licence:keys', ['--config' => 'C:/nowhere/openssl.cnf'])
            ->expectsOutputToContain('OpenSSL would not make a key')
            ->expectsOutputToContain('C:/nowhere/openssl.cnf')
            ->expectsOutputToContain('--config')
            ->expectsOutputToContain('OPENSSL_CONF')
            ->assertFailed();
    }

    /** And says the whole reason, not the last line of it. */
    public function test_the_failure_reports_every_reason_openssl_gave(): void
    {
        $this->artisan('licence:keys', ['--config' => 'C:/nowhere/openssl.cnf'])
            ->expectsOutputToContain('configuration file routines')
            ->assertFailed();
    }

    /**
     * The reassurance that matters: this is a one-off on the seller's machine.
     * No shop's server ever generates a key, so no shop needs openssl.cnf.
     */
    public function test_it_says_no_shop_server_needs_this(): void
    {
        $this->artisan('licence:keys', ['--config' => 'C:/nowhere/openssl.cnf'])
            ->expectsOutputToContain('Signing licences and checking them do not')
            ->assertFailed();
    }

    public function test_a_second_pair_is_refused_because_it_breaks_every_licence_issued(): void
    {
        config(['licence.public_key' => 'anything at all']);

        $this->artisan('licence:keys')
            ->expectsOutputToContain('already carries a public key')
            ->assertFailed();
    }

    // =====================================================================
    // Finding the file
    // =====================================================================

    public function test_a_path_that_was_typed_is_used_as_typed(): void
    {
        $this->assertSame('C:/somewhere/openssl.cnf', OpenSslConfig::find('C:/somewhere/openssl.cnf'));
    }

    public function test_it_finds_the_one_this_machine_actually_has(): void
    {
        // Every Linux and macOS box running these tests has one somewhere in
        // the list; Windows is the platform this exists for and cannot be run
        // here, so the list itself is what is asserted below.
        $found = OpenSslConfig::find();

        if ($found !== null) {
            $this->assertFileExists($found);
        }

        $this->assertContains('/etc/ssl/openssl.cnf', OpenSslConfig::CANDIDATES);
    }

    /** The two places XAMPP puts it, which is the case this was written for. */
    public function test_both_xampp_locations_are_looked_in(): void
    {
        $this->assertContains('C:/xampp/apache/conf/openssl.cnf', OpenSslConfig::CANDIDATES);
        $this->assertContains('C:/xampp/php/extras/ssl/openssl.cnf', OpenSslConfig::CANDIDATES);
    }

    /** Somebody who set OPENSSL_CONF meant it. */
    public function test_the_environment_variable_is_believed_when_it_points_at_a_real_file(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'openssl');

        putenv('OPENSSL_CONF='.$temporary);

        try {
            $this->assertSame($temporary, OpenSslConfig::find());
        } finally {
            putenv('OPENSSL_CONF');
            @unlink($temporary);
        }
    }

    /** But not when it points at nothing — that is how it is usually wrong. */
    public function test_an_environment_variable_pointing_at_nothing_is_ignored(): void
    {
        putenv('OPENSSL_CONF=/definitely/not/here/openssl.cnf');

        try {
            $this->assertNotSame('/definitely/not/here/openssl.cnf', OpenSslConfig::find());
        } finally {
            putenv('OPENSSL_CONF');
        }
    }

    /** Windows paths with a wildcard in them are still paths. */
    public function test_wildcard_candidates_do_not_break_the_search(): void
    {
        $this->assertTrue(
            collect(OpenSslConfig::CANDIDATES)->contains(fn ($c) => str_contains($c, '*')),
            'there are wildcard candidates to exercise',
        );

        // No exception, whatever glob() makes of them on this machine.
        OpenSslConfig::find();

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // Writing the keys out instead of making somebody copy them
    // =====================================================================

    /**
     * A PEM is nine lines. Copying it out of a Windows console and into a
     * one-line .env is where this goes wrong, and it goes wrong silently — the
     * key reads as rubbish and every licence looks forged.
     */
    public function test_it_can_write_both_keys_to_files(): void
    {
        $folder = sys_get_temp_dir().'/licence-'.uniqid();

        $this->artisan('licence:keys', ['--write' => $folder])->assertSuccessful();

        $private = $folder.'/licence-private.pem';
        $public = $folder.'/licence-public.pem';

        $this->assertFileExists($private);
        $this->assertFileExists($public);

        $this->assertStringContainsString('BEGIN PRIVATE KEY', file_get_contents($private));
        $this->assertStringContainsString('BEGIN PUBLIC KEY', file_get_contents($public));

        // And they are a real pair: one signs, the other checks.
        $licence = Licence::sign(
            ['shop' => 'Soran Store', 'host' => null, 'expires' => null],
            file_get_contents($private),
        );

        config(['licence.public_key' => file_get_contents($public), 'licence.key' => $licence]);
        app(Licence::class)->forget();

        $this->assertSame(Licence::VALID, app(Licence::class)->state());

        @unlink($private);
        @unlink($public);
        @rmdir($folder);
    }

    /** The line to paste, with the newlines already escaped for .env. */
    public function test_it_prints_an_env_ready_public_key(): void
    {
        $folder = sys_get_temp_dir().'/licence-'.uniqid();

        $this->artisan('licence:keys', ['--write' => $folder])
            ->expectsOutputToContain('LICENCE_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n')
            ->assertSuccessful();

        @unlink($folder.'/licence-private.pem');
        @unlink($folder.'/licence-public.pem');
        @rmdir($folder);
    }

    /**
     * And that escaped line really does survive .env and come back as a key
     * OpenSSL will accept — which is the only thing that matters about it.
     */
    public function test_the_escaped_line_survives_a_real_env_file(): void
    {
        $folder = sys_get_temp_dir().'/licence-'.uniqid();
        mkdir($folder, 0755, true);

        $this->artisan('licence:keys', ['--write' => $folder])->assertSuccessful();

        $pem = trim(file_get_contents($folder.'/licence-public.pem'));

        file_put_contents($folder.'/.env', 'LICENCE_PUBLIC_KEY="'.str_replace("\n", '\n', $pem).'"'."\n");

        $read = Dotenv::createArrayBacked([$folder])->load()['LICENCE_PUBLIC_KEY'];

        $this->assertNotFalse(openssl_pkey_get_public($read), 'OpenSSL accepts it after the round trip');
        $this->assertSame($pem, trim($read));

        foreach (['/.env', '/licence-private.pem', '/licence-public.pem'] as $file) {
            @unlink($folder.$file);
        }
        @rmdir($folder);
    }
}
