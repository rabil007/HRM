<?php

namespace App\Services;

use App\Models\WhatsAppSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppService
{
    /**
     * @param  array<string, mixed>|null  $override
     * @return array{success: bool, message: string, phone?: array<string, mixed>}
     */
    public function testConnection(?array $override = null): array
    {
        $credentials = $this->resolveCredentials($override, requireEnabled: false);

        $response = $this->client($credentials['access_token'])
            ->get("/{$credentials['phone_number_id']}", [
                'fields' => 'verified_name,display_phone_number',
            ]);

        if ($response->successful()) {
            $phone = $response->json();

            return [
                'success' => true,
                'message' => 'Connection successful.',
                'phone' => is_array($phone) ? $phone : [],
            ];
        }

        return [
            'success' => false,
            'message' => $this->parseMetaError($response),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTextMessage(string $phone, string $text): array
    {
        $credentials = $this->resolveCredentials();

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($phone),
            'type' => 'text',
            'text' => [
                'body' => $text,
            ],
        ];

        $response = $this->client($credentials['access_token'])
            ->post($this->messagesPath($credentials['phone_number_id']), $payload);

        $result = $this->parseMessageResponse($response, 'POST', $credentials['phone_number_id'], $payload);
        $result['normalized_phone'] = $this->normalizePhone($phone);
        $result['delivery_note'] = 'Text messages only appear if the recipient messaged your business number within the last 24 hours, or is on your Meta test recipient list.';

        return $result;
    }

    /**
     * @return array{media_id: string, api: array<string, mixed>}
     */
    private function uploadDocumentWithMeta(string $filePath, ?string $mime = null): array
    {
        if (! is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        $credentials = $this->resolveCredentials();
        $mime ??= mime_content_type($filePath) ?: 'application/octet-stream';
        $payload = [
            'messaging_product' => 'whatsapp',
            'type' => $mime,
        ];

        $response = $this->client($credentials['access_token'])
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($this->mediaPath($credentials['phone_number_id']), $payload);

        $api = $this->buildApiExchange('POST', $credentials['phone_number_id'], $this->mediaPath($credentials['phone_number_id']), [
            ...$payload,
            'file' => basename($filePath),
        ], $response);

        if (! $response->successful()) {
            throw new RuntimeException($this->parseMetaError($response));
        }

        $mediaId = $response->json('id');

        if (! is_string($mediaId) || $mediaId === '') {
            throw new RuntimeException('WhatsApp media upload did not return a media ID.');
        }

        return [
            'media_id' => $mediaId,
            'api' => $api,
        ];
    }

    public function uploadDocument(string $filePath, ?string $mime = null): string
    {
        return $this->uploadDocumentWithMeta($filePath, $mime)['media_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(string $phone, string $filePath, string $fileName, ?string $caption = null): array
    {
        $upload = $this->uploadDocumentWithMeta($filePath);
        $credentials = $this->resolveCredentials();

        $document = [
            'id' => $upload['media_id'],
            'filename' => $fileName,
        ];

        if (filled($caption)) {
            $document['caption'] = $caption;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($phone),
            'type' => 'document',
            'document' => $document,
        ];

        $response = $this->client($credentials['access_token'])
            ->post($this->messagesPath($credentials['phone_number_id']), $payload);

        $result = $this->parseMessageResponse($response, 'POST', $credentials['phone_number_id'], $payload);
        $result['media_id'] = $upload['media_id'];
        $result['media_api'] = $upload['api'];
        $result['normalized_phone'] = $this->normalizePhone($phone);
        $result['delivery_note'] = 'Documents only appear if the recipient messaged your business number within the last 24 hours, or is on your Meta test recipient list.';

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTemplateMessage(string $phone): array
    {
        return $this->sendTemplate($phone, 'hello_world', 'en_US');
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function sendTemplate(
        string $phone,
        string $templateName,
        string $languageCode,
        array $components = [],
    ): array {
        $credentials = $this->resolveCredentials();

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode,
                ],
            ],
        ];

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        $response = $this->client($credentials['access_token'])
            ->post($this->messagesPath($credentials['phone_number_id']), $payload);

        $result = $this->parseMessageResponse($response, 'POST', $credentials['phone_number_id'], $payload);
        $result['normalized_phone'] = $this->normalizePhone($phone);
        $result['delivery_note'] = 'Template messages can be delivered outside the 24-hour session window.';

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{business_account_id: string, phone_number_id: string, access_token: string, app_id: string, app_secret: string, webhook_verify_token: string, enabled: bool}
     */
    private function resolveCredentials(?array $override = null, bool $requireEnabled = true): array
    {
        $settings = WhatsAppSetting::current();

        $credentials = [
            'business_account_id' => (string) ($override['business_account_id'] ?? $settings->business_account_id ?? ''),
            'phone_number_id' => (string) ($override['phone_number_id'] ?? $settings->phone_number_id ?? ''),
            'access_token' => (string) ($override['access_token'] ?? $settings->access_token ?? ''),
            'app_id' => (string) ($override['app_id'] ?? $settings->app_id ?? ''),
            'app_secret' => (string) ($override['app_secret'] ?? $settings->app_secret ?? ''),
            'webhook_verify_token' => (string) ($override['webhook_verify_token'] ?? $settings->webhook_verify_token ?? ''),
            'enabled' => (bool) ($override['enabled'] ?? $settings->enabled),
        ];

        if ($requireEnabled && ! $credentials['enabled']) {
            throw new RuntimeException('WhatsApp integration is disabled.');
        }

        foreach (['phone_number_id', 'access_token'] as $required) {
            if ($credentials[$required] === '') {
                throw new RuntimeException('WhatsApp credentials are incomplete.');
            }
        }

        return $credentials;
    }

    private function client(string $accessToken): PendingRequest
    {
        $version = config('whatsapp.graph_api_version');
        $baseUrl = rtrim((string) config('whatsapp.graph_base_url'), '/')."/{$version}";

        return Http::withToken($accessToken)
            ->timeout((int) config('whatsapp.timeout'))
            ->acceptJson()
            ->baseUrl($baseUrl);
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // UAE local numbers are often entered as +9710XXXXXXXX — drop the trunk zero.
        if (str_starts_with($digits, '9710') && strlen($digits) >= 13) {
            $digits = '971'.substr($digits, 4);
        }

        // Generic international format with leading zero after country code (e.g. 97105...).
        if (preg_match('/^(\d{1,3})0(\d{6,})$/', $digits, $matches) === 1) {
            $digits = $matches[1].$matches[2];
        }

        return $digits;
    }

    private function messagesPath(string $phoneNumberId): string
    {
        return "/{$phoneNumberId}/messages";
    }

    private function mediaPath(string $phoneNumberId): string
    {
        return "/{$phoneNumberId}/media";
    }

    private function graphUrl(string $phoneNumberId, string $path): string
    {
        $version = config('whatsapp.graph_api_version');
        $baseUrl = rtrim((string) config('whatsapp.graph_base_url'), '/');

        return "{$baseUrl}/{$version}{$path}";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildApiExchange(
        string $method,
        string $phoneNumberId,
        string $path,
        array $payload,
        Response $response,
    ): array {
        $body = $response->json();
        $parsedBody = is_array($body) ? $body : ['raw' => $response->body()];

        return [
            'request' => [
                'method' => $method,
                'url' => $this->graphUrl($phoneNumberId, $path),
                'payload' => $payload,
            ],
            'response' => [
                'http_status' => $response->status(),
                'body' => $parsedBody,
            ],
        ];
    }

    private function parseMetaError(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'WhatsApp API request failed with status '.$response->status().'.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function parseMessageResponse(
        Response $response,
        string $method,
        string $phoneNumberId,
        array $payload,
    ): array {
        $data = $response->json();
        $messageId = is_array($data) ? ($data['messages'][0]['id'] ?? null) : null;
        $api = $this->buildApiExchange($method, $phoneNumberId, $this->messagesPath($phoneNumberId), $payload, $response);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Message accepted by Meta.',
                'message_id' => is_string($messageId) ? $messageId : null,
                'http_status' => $response->status(),
                'data' => is_array($data) ? $data : null,
                'api' => $api,
            ];
        }

        return [
            'success' => false,
            'message' => $this->parseMetaError($response),
            'message_id' => null,
            'http_status' => $response->status(),
            'data' => is_array($data) ? $data : null,
            'api' => $api,
        ];
    }
}
