<?php

use App\Models\EmailTemplate;

/**
 * @param  array{to_preset?: string|null, cc_preset?: string|null, enabled?: bool}  $overrides
 */
function configureDocumentExpiryAlertTemplate(array $overrides = []): void
{
    $attributes = array_merge([
        'label' => 'Document expiry alert',
        'category' => 'notification',
        'to_preset' => 'hr@example.com',
        'cc_preset' => 'manager@example.com, hr@example.com',
        'subject' => 'Document Expiry Alert - Next 30 Days',
        'body_html' => 'Automated expiry summary email.',
        'is_default' => true,
        'enabled' => true,
        'sort_order' => 0,
    ], $overrides);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'document_expiry_alert'],
        $attributes,
    );
}
