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

        // How many rows the "largest supply gaps" table returns.
        'supply_gaps_limit' => env('MIE_SUPPLY_GAPS_LIMIT', 5),

        // How far back the "needs your action" activity feed looks, and how many items it returns.
        'activity_feed_days' => env('MIE_ACTIVITY_FEED_DAYS', 7),
        'activity_feed_limit' => env('MIE_ACTIVITY_FEED_LIMIT', 8),
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
