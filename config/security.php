<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Privileged two-factor enforcement
    |--------------------------------------------------------------------------
    |
    | When enabled, users who hold high-trust capabilities must have Fortify
    | two-factor authentication enrolled and confirmed before those actions
    | run. This does not replace tenant, permission, or login checks.
    |
    | Default is off so existing installations can enroll before turning
    | enforcement on. Production should set PRIVILEGED_2FA_ENFORCED=true
    | after operators complete 2FA setup. See docs/privileged-2fa.md.
    |
    */
    'privileged_two_factor' => [
        'enforced' => env('PRIVILEGED_2FA_ENFORCED', false),
    ],

];
