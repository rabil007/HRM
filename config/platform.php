<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform database viewer
    |--------------------------------------------------------------------------
    |
    | Cross-tenant schema browsing is a platform diagnostic, not tenant
    | functionality. When PLATFORM_DATABASE_VIEWER_ENABLED is unset, the
    | viewer is available outside production and disabled in production.
    | Set the env var to true or false to override that default.
    |
    */
    'database_viewer' => [
        'enabled' => env('PLATFORM_DATABASE_VIEWER_ENABLED'),
    ],

];
