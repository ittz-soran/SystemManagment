<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The root URL sends visitors to the dashboard, which requires a login. */
    public function test_the_root_url_redirects_into_the_application(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));

        // Not logged in, so the dashboard bounces to the login screen.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
