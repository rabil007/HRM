<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document expiry email alerts
    |--------------------------------------------------------------------------
    |
    | Scheduled command documents:send-expiry-alerts emails stakeholders when
    | employee documents enter the alert window (default: 30 days before expiry).
    | Configure recipients in Settings → Email templates → Document expiry alert.
    |
    */

    'expiry_alert_days' => (int) env('DOCUMENT_EXPIRY_ALERT_DAYS', 30),

    'expiry_alert_template_slug' => 'document_expiry_alert',

];
