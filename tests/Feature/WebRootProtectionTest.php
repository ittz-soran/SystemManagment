<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Nothing above `public/` may be served.
 *
 * Shared hosting rarely lets the document root move, so the whole system gets
 * uploaded inside public_html and the shop is reached at /sys/public/. Every
 * other file is then a URL away — .env first among them, holding the database
 * password and the APP_KEY that decrypts the staff's authenticator secrets.
 *
 * docs/INSTALL.md has said to fix that per install since the beginning, and
 * saying it is not the same as shipping it. These two files are the net, and
 * they only work as a pair: the deny in the root cascades into subfolders, so
 * public/ has to grant itself back or the site returns 403 to everybody.
 *
 * A test rather than a comment because the failure is silent in both
 * directions — a missing deny leaks .env with the shop working perfectly, and
 * a missing grant takes the shop down without touching a line of PHP.
 */
class WebRootProtectionTest extends TestCase
{
    public function test_the_root_htaccess_denies_everything(): void
    {
        $path = base_path('.htaccess');

        $this->assertFileExists($path, 'the root .htaccess is what keeps .env off the web');

        $rules = file_get_contents($path);

        $this->assertStringContainsString('Require all denied', $rules, 'Apache 2.4');
        $this->assertStringContainsString('Deny from all', $rules, 'Apache 2.2');
    }

    public function test_public_grants_itself_back(): void
    {
        $rules = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('Require all granted', $rules, 'Apache 2.4');
        $this->assertStringContainsString('Allow from all', $rules, 'Apache 2.2');
    }

    /** The front controller still has to be reachable through it. */
    public function test_public_still_sends_requests_to_the_front_controller(): void
    {
        $this->assertStringContainsString(
            'RewriteRule ^ index.php [L]',
            file_get_contents(public_path('.htaccess')),
        );
    }

    /**
     * The files that would be readable if the deny were ever dropped.
     *
     * Named individually so the reason survives: these are not "config files",
     * they are the database password and the key that decrypts every staff
     * member's authenticator secret.
     */
    public function test_the_files_this_protects_really_are_above_public(): void
    {
        foreach (['.env.example', 'composer.json', 'artisan'] as $file) {
            $this->assertFileExists(base_path($file));
            $this->assertFileDoesNotExist(public_path($file), "{$file} must not be inside public/");
        }
    }
}
