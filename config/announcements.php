<?php

return [
    'attachments' => [
        'disk' => 'local',
        'max_file_bytes' => 10 * 1024 * 1024,
        'max_total_bytes' => 30 * 1024 * 1024,
        'allowed_extensions' => ['pdf', 'docx', 'xlsx', 'jpeg', 'jpg', 'png'],
        'allowed_mimes' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
        ],
    ],
];
