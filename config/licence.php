<?php

return [

    /*
    |---------------------------------------------------------------------------
    | The seller's public key
    |---------------------------------------------------------------------------
    |
    | Generated once with `php artisan licence:keys`, which prints a matching
    | pair. The PUBLIC half goes here, is committed, and ships with every copy of
    | the system. The PRIVATE half never leaves the seller's own machine — it is
    | the only thing that can issue a licence, and anybody holding it can issue
    | themselves a free one for ever.
    |
    | Leave it empty and there is no licensing at all: no check, no banner,
    | nothing refused. That is what an install that was not sold should look
    | like, and it is what every existing install stays as until a key is put
    | here on purpose.
    |
    */

    'public_key' => env('LICENCE_PUBLIC_KEY', 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAlmc8+lMgHJby2ujKuDRG
EV+GJUmbMaKtjTrFVYJuhxSS0JIXlPXOOS7KHZ8Q4AopVVVCO3mGkODKudscLWiw
nt7FZWnPJg8XyIfjn+T3gGL2qLweG/goErWHP6WJXys3yB8qR4oup6m5jiA0S/mv
4hz57MC6ek+jm0AnO57YHBuGoFITgXjfHNUurTrJ4YkwZ3bU7UjBR5SsOy/TMIFH
XrlDZBusilPfTS+1FWfW/kgPftbHcyTq8JXsgaXATpQzfkOA3UNH+0j7aSsb0RuY
wMTRd7I3p+ZADqxKuVOxJ0ip9VQFyzBBACMsR8grDOmRNWb8fDtTqiuJsQZFBwkS
ZwIDAQAB'),

    /*
    |---------------------------------------------------------------------------
    | This shop's licence
    |---------------------------------------------------------------------------
    |
    | The signed string `php artisan licence:issue` prints. Goes in .env on the
    | server:
    |
    |     LICENCE_KEY=eyJzaG9wIjoi...
    |
    | It cannot be forged, extended or moved to another domain, because the
    | signature covers all of that — but it can be READ by anybody who can see
    | the file, and there is nothing secret in it worth hiding.
    |
    */

    'key' => env('LICENCE_KEY', ''),

    /*
    |---------------------------------------------------------------------------
    | After the date
    |---------------------------------------------------------------------------
    |
    | A shop does not stop trading on the stroke of midnight because an invoice
    | is late. The days here are how long everything keeps working normally
    | after the licence expires, with the warning growing louder — time enough
    | for a payment to clear or a phone call to be returned.
    |
    | Only when those are gone does the system go read-only. Reading, printing,
    | deleting and signing in never stop, whatever the licence says: a shop
    | locked out of its own records is a shop that will never pay another
    | invoice.
    |
    */

    'grace_days' => (int) env('LICENCE_GRACE_DAYS', 7),

    /** How long before the date to start saying so. */
    'warn_days' => (int) env('LICENCE_WARN_DAYS', 14),

];
