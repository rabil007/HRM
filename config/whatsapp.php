<?php

return [
    'graph_api_version' => env('WHATSAPP_GRAPH_API_VERSION', 'v21.0'),
    'graph_base_url' => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 30),
    'webhook_route_name' => 'whatsapp.webhook',
    'test_message' => 'Hello from Herd OMS. WhatsApp test message.',
    'document_caption' => 'Document shared from Herd OMS',
];
