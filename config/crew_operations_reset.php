<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed database hosts
    |--------------------------------------------------------------------------
    |
    | The crew:reset command refuses to run unless the default connection host
    | matches one of these values (local development safety).
    |
    */
    'allowed_hosts' => [
        '127.0.0.1',
        'localhost',
        '::1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed database names
    |--------------------------------------------------------------------------
    */
    'allowed_databases' => [
        'oms_hrm',
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing override
    |--------------------------------------------------------------------------
    |
    | When true, crew:reset may run under APP_ENV=testing (sqlite :memory:).
    |
    */
    'allow_in_testing' => (bool) env('CREW_OPERATIONS_RESET_ALLOW_TESTING', false),

    /*
    |--------------------------------------------------------------------------
    | Interactive confirmation phrase
    |--------------------------------------------------------------------------
    */
    'confirmation_phrase' => 'RESET CREW OPERATIONS DATA',

    /*
    |--------------------------------------------------------------------------
    | Assignment numbering
    |--------------------------------------------------------------------------
    */
    'sequence_reset_year' => 2026,

];
