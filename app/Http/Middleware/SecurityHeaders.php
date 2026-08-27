<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers a browser needs in order to help.
 *
 * Written for how this shop is actually served: everything the pages load —
 * stylesheet, script, fonts, the logo — comes from the shop's own address.
 * Nothing is fetched from a CDN, so `default-src 'self'` costs nothing and
 * turns the browser into a second pair of hands: a script that did get injected
 * still cannot post what it read to somebody else's server, because the
 * connection is refused before it opens.
 *
 * `'unsafe-inline'` for scripts is not an oversight. Section 9b's screens carry
 * their own inline <script> blocks with translated strings in them, and a
 * policy that forbade those would take the till offline. It weakens the
 * script-src rule and leaves the rest standing, which is worth having: the
 * escaping in partials/escape-html is what stops the injection, and this is
 * what limits the damage if some corner of the system ever misses it.
 */
class SecurityHeaders
{
    private const POLICY = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', self::POLICY));

        // The shop's own logo is served by reading a file off disk and sending
        // it. Told not to guess, a browser will not decide that an upload is
        // something executable.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Nothing here is ever meant to be shown inside somebody else's page.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Invoice URLs carry document numbers. They stay inside the shop.
        $response->headers->set('Referrer-Policy', 'same-origin');

        // A till has no use for the camera, the microphone or the customer's
        // whereabouts, so it asks for none of them.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
