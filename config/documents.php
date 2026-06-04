<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document expiry email alerts
    |--------------------------------------------------------------------------
    |
    | Daily scheduler dispatches queued jobs per company. Each job sends one
    | consolidated email for documents not yet alerted for their current expiry
    | date (within the configured day window).
    |
    | Configure To/CC and daily dispatch time in Settings → Email templates →
    | Document expiry alert (slug: document_expiry_alert).
    |
    */

    'expiry_alert_days' => (int) env('DOCUMENT_EXPIRY_ALERT_DAYS', 30),

    'expiry_alert_template_slug' => 'document_expiry_alert',

    'expiry_alert_dispatch_at' => env('DOCUMENT_EXPIRY_ALERT_DISPATCH_AT', '08:00'),

];
