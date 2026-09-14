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

    /*
    |--------------------------------------------------------------------------
    | Company document expiry email alerts
    |--------------------------------------------------------------------------
    |
    | Recipients come from company-level notification settings, not this template.
    | The template controls dispatch_at timing, subject, and company footer.
    |
    */

    'company_expiry_alert_template_slug' => 'company_document_expiry_alert',

];
