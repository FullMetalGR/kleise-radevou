<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The console's clock
    |--------------------------------------------------------------------------
    |
    | The zone every instant on the platform console is spelled in, named
    | beside it: the console is on no tenant's clock.
    | Europe/Athens unless the environment names another.
    | Read through App\Platform\ConsoleTime and nothing else.
    |
    */

    'timezone' => env('CONSOLE_TIMEZONE', 'Europe/Athens'),

];
