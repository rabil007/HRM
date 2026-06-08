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

    'api_host' => env('HIKVISION_API_HOST'),

    'api_key' => env('HIKVISION_API_KEY'),

    'api_secret' => env('HIKVISION_API_SECRET'),

    'token_path' => '/api/hccgw/platform/v1/token/get',

    'devices_path' => '/api/hccgw/resource/v1/devices/get',

    'device_detail_path' => '/api/hccgw/resource/v1/devicedetail/get',

    'person_groups_search_path' => '/api/hccgw/person/v1/groups/search',

    'persons_list_path' => '/api/hccgw/person/v1/persons/list',

    'persons_page_size' => 100,

    'isapi_proxypass_path' => '/api/hccgw/video/v1/isapi/proxypass',

    'attendance_totaltimecard_path' => '/api/hccgw/attendance/v1/report/totaltimecard/list',

    'certificate_records_search_path' => '/api/hccgw/acs/v1/event/certificaterecords/search',

    'certificate_records_page_size' => 100,

    'persons_get_path' => '/api/hccgw/person/v1/persons/get',

    'persons_add_path' => '/api/hccgw/person/v1/persons/add',

    'persons_update_path' => '/api/hccgw/person/v1/persons/update',

    'persons_delete_path' => '/api/hccgw/person/v1/persons/delete',

    'persons_photo_path' => '/api/hccgw/person/v1/persons/photo',

    'webhook_config_save_path' => '/api/hccgw/webhook/v1/config/save',

    'rawmsg_mq_subscribe_path' => '/api/hccgw/rawmsg/v1/mq/subscribe',

    'webhook_verify_header' => 'X-HCC-Webhook-Token',

    /*
    |--------------------------------------------------------------------------
    | Temporary webhook debug logging (remove after production verification)
    |--------------------------------------------------------------------------
    |
    | Set HIKVISION_WEBHOOK_DEBUG=true in .env to log incoming webhook requests.
    | Logs use the prefix [Hikvision Webhook] in storage/logs/laravel.log.
    |
    */
    'webhook_debug_log' => (bool) env('HIKVISION_WEBHOOK_DEBUG', false),

    'acs_event_page_size' => 50,

    'attendance_page_size' => 200,

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
