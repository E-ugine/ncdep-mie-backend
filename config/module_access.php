<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Market Intelligence & Exchange — Module Access Gate
    |--------------------------------------------------------------------------
    |
    | Policy knobs for the second-factor gate (phone OTP + PIN) required to
    | enter the Market Intelligence and Exchange module, per spec section 1.1.
    | These are intentionally config-driven rather than hardcoded so the
    | lockout/expiry behaviour can be tuned per environment without code changes.
    |
    */

    'otp_ttl_minutes' => env('MODULE_ACCESS_OTP_TTL_MINUTES', 5),

    'otp_length' => env('MODULE_ACCESS_OTP_LENGTH', 6),

    'pin_max_attempts' => env('MODULE_ACCESS_PIN_MAX_ATTEMPTS', 5),

    'pin_lockout_minutes' => env('MODULE_ACCESS_PIN_LOCKOUT_MINUTES', 15),

    // How long a PIN verification stays "fresh" enough to satisfy the
    // requiresFreshPin guard used before contract signing / offer submission.
    'fresh_pin_window_minutes' => env('MODULE_ACCESS_FRESH_PIN_WINDOW_MINUTES', 5),

];
