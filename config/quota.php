<?php

return [

    /*
    |---------------------------------------------------------------------------
    | How much space this shop is allowed
    |---------------------------------------------------------------------------
    |
    | Set in .env on the server, never in the Settings screen, because this is
    | the plan the shop is paying for and not a preference of theirs:
    |
    |     STORAGE_LIMIT_MB=500
    |
    | Leave it unset and there is no limit and no meter — which is what every
    | install that is not sold on a plan should look like. Nothing about this
    | feature appears until a number is put here.
    |
    */

    'limit_mb' => env('STORAGE_LIMIT_MB'),

    /*
    |---------------------------------------------------------------------------
    | When to start saying so
    |---------------------------------------------------------------------------
    |
    | Full stops the shop writing, so it must never be the first they hear of
    | it. The meter turns amber at the first figure and red at the second, and
    | an admin gets a banner on every page from amber onwards — long enough to
    | ring somebody, not so early it becomes wallpaper.
    |
    */

    'warn_at' => (int) env('STORAGE_WARN_AT', 80),

    'critical_at' => (int) env('STORAGE_CRITICAL_AT', 95),

    /*
    |---------------------------------------------------------------------------
    | How often to measure
    |---------------------------------------------------------------------------
    |
    | Measuring means one information_schema query and a walk of two directories.
    | That is cheap, but not cheap enough to do on every request of a busy till,
    | so the answer is held for this many seconds. A backup clears it early,
    | since a backup is the one thing that moves the figure sharply.
    |
    */

    'cache_seconds' => (int) env('STORAGE_CACHE_SECONDS', 60),

];
