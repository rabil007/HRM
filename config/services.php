<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pdf' => [
        'ghostscript_binary' => env('GHOSTSCRIPT_BINARY', 'gs'),
    ],

    'documents' => [
        'email_max_attachment_bytes' => (int) env('DOCUMENT_EMAIL_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),
        'pdf_compression_enabled' => (bool) env('DOCUMENT_PDF_COMPRESSION_ENABLED', true),
        'pdf_compress_min_bytes' => (int) env('DOCUMENT_PDF_COMPRESS_MIN_BYTES', 5 * 1024 * 1024),
        'pdf_compression_setting' => env('DOCUMENT_PDF_COMPRESSION_SETTING', '/ebook'),
    ],

    'browsershot' => [
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        'puppeteer_cache_dir' => env('PUPPETEER_CACHE_DIR'),
    ],

];
