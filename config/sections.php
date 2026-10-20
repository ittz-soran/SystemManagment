<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Sections this shop does without, and sections it is trying
    |---------------------------------------------------------------------------
    |
    | PROJECT_DOC Section 8h. Set in .env on the server by Soran Panel, never in
    | the Settings screen, because the buyer does not decide what the seller
    | ships:
    |
    |     SHOP_SECTIONS_OFF=stock_rooms,second_hand,repairs
    |     SHOP_SECTIONS_ON=
    |
    | OFF names released sections this shop has not got. ON names sections
    | still in testing that this shop is trying. Leave both unset and every
    | released section is on, which is what every install looks like until
    | somebody decides otherwise.
    |
    | The names are the keys in App\Support\Sections. `php artisan
    | shop:sections` lists them, and reports any name here it does not know.
    |
    */

    'off' => env('SHOP_SECTIONS_OFF', ''),

    'on' => env('SHOP_SECTIONS_ON', ''),

    /*
    |---------------------------------------------------------------------------
    | The list itself — tests only
    |---------------------------------------------------------------------------
    |
    | Null means App\Support\Sections::LIST, which is where a section is added.
    | A test sets this to try a section in testing, because no section is in
    | testing until a new page ships as one.
    |
    */

    'list' => null,

];
