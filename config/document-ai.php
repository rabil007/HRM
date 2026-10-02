<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Document AI performance profile
    |--------------------------------------------------------------------------
    |
    | Document extraction intentionally uses a feature-specific fast model
    | profile so tuning it does not change Smart Search or Announcement AI.
    | Provider credentials still come from the centralized Application AI
    | settings service.
    |
    */

    'models' => [
        'openai' => 'gpt-6-luna',
        'openrouter' => 'openai/gpt-6-luna',
    ],

    'reasoning_effort' => 'low',
];
