<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hikvision OpenAPI Defaults
    |--------------------------------------------------------------------------
    |
    | Credentials are stored in hikvision_settings (or .env fallback).
    | Only non-secret defaults belong here.
    |
    */

    'timeout' => (int) env('HIKVISION_TIMEOUT', 20),

    'token_path' => '/api/hccgw/platform/v1/token/get',

    'users_path' => '/api/hccgw/platform/v1/users/get',

];
