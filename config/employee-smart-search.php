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
    | Fast default models
    |--------------------------------------------------------------------------
    |
    | Used only when an administrator has not stored an explicit model for the
    | selected provider. Stored Application Settings always override these.
    |
    */

    'default_models' => [
        'openai' => 'gpt-5.6-luna',
        'openrouter' => 'openai/gpt-5.6-luna',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a structured interpretation.
    |
    */

    'timeout' => (int) env('EMPLOYEE_SMART_SEARCH_TIMEOUT', 20),

];
