<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee Directory smart search (beta)
    |--------------------------------------------------------------------------
    |
    | Converts a short natural-language prompt into existing Employee Directory
    | filters. Stored Settings → Application → AI values are authoritative.
    | This env flag is only a bootstrap fallback when no setting is stored.
    | Default is off. Does not search employees itself.
    |
    */

    'enabled' => filter_var(env('EMPLOYEE_SMART_SEARCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Provider timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a structured interpretation. The SDK provider default
    | model is used; do not hardcode a rapidly changing model name here.
    |
    */

    'timeout' => (int) env('EMPLOYEE_SMART_SEARCH_TIMEOUT', 20),

];
