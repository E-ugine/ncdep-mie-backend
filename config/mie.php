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

    /*
    |--------------------------------------------------------------------------
    | Contract Center (section 3.11)
    |--------------------------------------------------------------------------
    */
    'contracts' => [
        // A contract counts as "expiring" when its delivery_date falls within this many days.
        'expiring_within_days' => env('MIE_CONTRACT_EXPIRING_WITHIN_DAYS', 14),
    ],

];
