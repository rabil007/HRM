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

    'devices_path' => '/api/hccgw/resource/v1/devices/get',

    'device_detail_path' => '/api/hccgw/resource/v1/devicedetail/get',

    'mq_subscribe_path' => '/api/hccgw/rawmsg/v1/mq/subscribe',

    'mq_messages_path' => '/api/hccgw/rawmsg/v1/mq/messages',

    'mq_messages_complete_path' => '/api/hccgw/rawmsg/v1/mq/messages/complete',

    'isapi_proxypass_path' => '/api/hccgw/video/v1/isapi/proxypass',

    'acs_event_page_size' => 50,

    /*
    |--------------------------------------------------------------------------
    | ACS event minors to ignore (door/system telemetry, not person access)
    |--------------------------------------------------------------------------
    |
    | Minor 21–24 are door open/close/timeout events with no person identity.
    | The portal "Access Record Retrieval" only shows successful authentications.
    |
    */
    'acs_ignored_minors' => [21, 22, 23, 24],

];
