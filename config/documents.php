<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document expiry email alerts
    |--------------------------------------------------------------------------
    |
    | Scheduler: routes/console.php (daily, timezone from Application settings).
    | Recipients + dispatch time: Settings → Email templates → Document expiry alert.
    |
    */

    'expiry_alert_days' => (int) env('DOCUMENT_EXPIRY_ALERT_DAYS', 30),

    'expiry_alert_template_slug' => 'document_expiry_alert',

    'expiry_alert_dispatch_at' => env('DOCUMENT_EXPIRY_ALERT_DISPATCH_AT', '08:00'),

];
