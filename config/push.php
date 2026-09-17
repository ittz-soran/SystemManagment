<?php

/**
 * Web push — Soran, 2026-09-17.
 *
 * ⚠️ **A shop with no keys simply does not push, and that is not an error.**
 * Every other way of reading the shop keeps working: the bell, the page, the
 * remembrances. `php artisan push:keys` makes a pair and prints the two lines
 * to paste into `.env`.
 *
 * The private key never leaves the server, exactly like the licence key.
 */
return [
    'public_key' => env('VAPID_PUBLIC_KEY') ?: null,
    'private_key' => env('VAPID_PRIVATE_KEY') ?: null,

    /*
     * Who the push service should complain to. Apple and Google both want a
     * contactable subject on every message; a mailto is what the spec asks for.
     */
    'subject' => env('VAPID_SUBJECT') ?: env('APP_URL', 'https://example.com'),
];
