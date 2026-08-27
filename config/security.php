<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Privileged two-factor enforcement
    |--------------------------------------------------------------------------
    |
    | When enabled, users who hold high-trust capabilities must have Fortify
    | two-factor authentication enrolled and confirmed before those actions
    | run. This does not replace tenant, permission, or login checks.
    |
    | Default is off so existing installations can enroll before turning
    | enforcement on. Production should set PRIVILEGED_2FA_ENFORCED=true
    | after operators complete 2FA setup. See docs/privileged-2fa.md.
    |
    */
    'privileged_two_factor' => [
        'enforced' => env('PRIVILEGED_2FA_ENFORCED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser security headers
    |--------------------------------------------------------------------------
    |
    | Laravel emits these on web responses. Reverse proxies may also set HSTS
    | or nosniff; the middleware will not overwrite an existing HSTS header.
    | See docs/security-headers.md.
    |
    */
    'headers' => [
        'csp' => [
            'report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
            'frame_ancestors' => env(
                'SECURITY_CSP_FRAME_ANCESTORS',
                "'self' https://*.overseas-ms.com https://overseas-ms.com",
            ),
            'vite_dev_origins' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env(
                    'SECURITY_CSP_VITE_ORIGINS',
                    'https://oms-hrm.test:5173,wss://oms-hrm.test:5173,http://127.0.0.1:5173,ws://127.0.0.1:5173',
                )),
            ))),
        ],
        'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'SAMEORIGIN'),
        'hsts' => [
            'enabled' => env('SECURITY_HSTS'),
            'max_age' => 31536000,
            'include_subdomains' => true,
        ],
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), accelerometer=(), gyroscope=(), magnetometer=(), display-capture=(), browsing-topics=()',
    ],

];
