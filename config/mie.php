<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Market Command Center (section 3.1)
    |--------------------------------------------------------------------------
    */
    'command_center' => [
        // How many days back counts as "new" for the new-buyer-requirements tile.
        'new_requirement_days' => env('MIE_NEW_REQUIREMENT_DAYS', 7),
    ],

];
