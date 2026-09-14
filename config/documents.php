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
    | Recipients and the enabled switch come from company-level notification
    | settings (Company Documents → Expiry Notification Settings), not this
    | template. Subject and body are owned by CompanyDocumentExpiryAlertMail
    | and its Blade view. Dispatch time is the shared documents:dispatch-expiry-alerts
    | scheduler (configured on the employee document_expiry_alert template).
    |
    | The company_document_expiry_alert EmailTemplate is retained only for
    | include_company_footer. Its enabled flag does not stop delivery.
    |
    */

    'company_expiry_alert_template_slug' => 'company_document_expiry_alert',

];
