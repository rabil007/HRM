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

    /*
    |--------------------------------------------------------------------------
    | Bulk extraction concurrency
    |--------------------------------------------------------------------------
    |
    | How many Document AI files a single queue worker may extract at once
    | via ProcessDocumentAiBatchInParallelJob. Keep this modest so provider
    | rate limits and PHP process memory stay healthy.
    |
    */

    'batch_concurrency' => max(1, (int) env('DOCUMENT_AI_BATCH_CONCURRENCY', 3)),
];
