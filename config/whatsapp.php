<?php

return [
    'graph_api_version' => env('WHATSAPP_GRAPH_API_VERSION', 'v25.0'),
    'graph_base_url' => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 30),
    'webhook_route_name' => 'whatsapp.webhook',
    'test_message' => 'Hello from Herd OMS. WhatsApp test message.',
    'document_caption' => 'Document shared from Herd OMS',
    'meta_template_manager_url' => env(
        'WHATSAPP_META_TEMPLATE_MANAGER_URL',
        'https://business.facebook.com/wa/manage/message-templates/',
    ),
    'default_document_template' => [
        'slug' => 'document_delivery',
        'label' => 'Document delivery',
        'meta_name' => 'document_delivery',
        'meta_language' => 'en',
        'body_preview' => 'Hello {{name}}, Please find the attached document from Overseas Marine Services. Thank you.',
    ],
];
