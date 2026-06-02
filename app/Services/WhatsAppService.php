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

        $response = $this->client($credentials['access_token'])
            ->post("/{$credentials['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizePhone($phone),
                'type' => 'text',
                'text' => [
                    'body' => $text,
                ],
            ]);

        return $this->parseMessageResponse($response);
    }

    public function uploadDocument(string $filePath, ?string $mime = null): string
    {
        if (! is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        $credentials = $this->resolveCredentials();
        $mime ??= mime_content_type($filePath) ?: 'application/octet-stream';

        $response = $this->client($credentials['access_token'])
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post("/{$credentials['phone_number_id']}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->parseMetaError($response));
        }

        $mediaId = $response->json('id');

        if (! is_string($mediaId) || $mediaId === '') {
            throw new RuntimeException('WhatsApp media upload did not return a media ID.');
        }

        return $mediaId;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(string $phone, string $filePath, string $fileName, ?string $caption = null): array
    {
        $mediaId = $this->uploadDocument($filePath);
        $credentials = $this->resolveCredentials();

        $document = [
            'id' => $mediaId,
            'filename' => $fileName,
        ];

        if (filled($caption)) {
            $document['caption'] = $caption;
        }

        $response = $this->client($credentials['access_token'])
            ->post("/{$credentials['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizePhone($phone),
                'type' => 'document',
                'document' => $document,
            ]);

        $result = $this->parseMessageResponse($response);
        $result['media_id'] = $mediaId;

        return $result;
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
            ->post("/{$credentials['phone_number_id']}/messages", $payload);

        return $this->parseMessageResponse($response);
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
        return preg_replace('/\D+/', '', $phone) ?? '';
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
     * @return array<string, mixed>
     */
    private function parseMessageResponse(Response $response): array
    {
        $data = $response->json();
        $messageId = is_array($data) ? ($data['messages'][0]['id'] ?? null) : null;

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Message sent successfully.',
                'message_id' => is_string($messageId) ? $messageId : null,
                'http_status' => $response->status(),
                'data' => is_array($data) ? $data : null,
            ];
        }

        return [
            'success' => false,
            'message' => $this->parseMetaError($response),
            'message_id' => null,
            'http_status' => $response->status(),
            'data' => is_array($data) ? $data : null,
        ];
    }
}
