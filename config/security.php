<?php

return [
    'privileged_two_factor' => [
        'enforce' => (bool) env('PRIVILEGED_2FA_ENFORCE', false),
        'permissions' => [
            'roles.update',
            'users.update',
            'settings.application.update',
            'settings.integrations.whatsapp.update',
            'settings.integrations.hikvision.update',
            'payroll.periods.approve',
            'payroll.periods.mark_paid',
            'payroll.periods.revert_to_draft',
            'payroll.periods.revert_to_approved',
            'payroll.periods.revert_to_processing',
            'crew_operations.corrections.override',
            'crew_operations.assignments.void',
            'bulk_documents.signatures.review',
        ],
    ],
];
