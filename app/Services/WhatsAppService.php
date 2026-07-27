<?php

namespace App\Services;

use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    private function uploadDocumentWithMeta(string $filePath, ?string $mime = null, ?string $fileName = null): array
    {
        if (! is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        $credentials = $this->resolveCredentials();
        $mime = $this->resolveMimeType($filePath, $mime, $fileName);
        $uploadFileName = $fileName ?: basename($filePath);
        $payload = [
            'messaging_product' => 'whatsapp',
            'type' => $mime,
        ];

        $response = $this->client($credentials['access_token'])
            ->attach('file', file_get_contents($filePath), $uploadFileName, [
                'Content-Type' => $mime,
            ])
            ->post($this->mediaPath($credentials['phone_number_id']), $payload);

        $api = $this->buildApiExchange('POST', $credentials['phone_number_id'], $this->mediaPath($credentials['phone_number_id']), [
            ...$payload,
            'file' => $uploadFileName,
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

    public function uploadDocument(string $filePath, ?string $mime = null, ?string $fileName = null): string
    {
        return $this->uploadDocumentWithMeta($filePath, $mime, $fileName)['media_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(string $phone, string $filePath, string $fileName, ?string $caption = null, ?string $mime = null): array
    {
        $upload = $this->uploadDocumentWithMeta($filePath, $mime, $fileName);
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
        $version = (string) config('whatsapp.graph_api_version', 'v25.0');
        $baseUrl = rtrim((string) config('whatsapp.graph_base_url'), '/')."/{$version}";

        return Http::withToken($accessToken)
            ->timeout((int) config('whatsapp.timeout'))
            ->acceptJson()
            ->baseUrl($baseUrl);
    }

    /**
     * @return array{success: bool, message: string, message_id: string|null, http_status: int, normalized_phone: string}
     */
    public function sendDocumentTemplate(
        string $phone,
        string $employeeName,
        string $documentUrl,
        string $fileName,
        WhatsAppTemplate|string|null $templateOrSlug = null,
    ): array {
        $phone = trim($phone);

        if ($phone === '') {
            throw new InvalidArgumentException('Phone number is required.');
        }

        $credentials = $this->resolveCredentials();
        $normalizedPhone = $this->normalizePhone($phone);

        if ($normalizedPhone === '') {
            throw new InvalidArgumentException('Phone number is invalid.');
        }

        $template = match (true) {
            $templateOrSlug instanceof WhatsAppTemplate => $templateOrSlug,
            is_string($templateOrSlug) && $templateOrSlug !== '' => WhatsAppTemplate::resolveBySlug($templateOrSlug),
            default => WhatsAppTemplate::defaultForCategory(WhatsAppTemplateCategory::Document),
        };

        if ($template->header_type !== WhatsAppTemplateHeaderType::Document) {
            throw new InvalidArgumentException('Selected template must use a document header.');
        }

        $templateName = $template->meta_name;
        $templateLanguage = $template->meta_language;

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $templateLanguage,
                ],
                'components' => [
                    [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type' => 'document',
                                'document' => [
                                    'link' => $documentUrl,
                                    'filename' => $fileName,
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'body',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $employeeName,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Log::info('WhatsApp document template request', [
            'phone' => $normalizedPhone,
            'employee_name' => $employeeName,
            'document_url' => $documentUrl,
            'file_name' => $fileName,
            'template' => $templateName,
            'template_slug' => $template->slug,
            'template_language' => $templateLanguage,
            'payload' => $payload,
        ]);

        $response = $this->client($credentials['access_token'])
            ->post($this->messagesPath($credentials['phone_number_id']), $payload);

        Log::info('WhatsApp document template response', [
            'phone' => $normalizedPhone,
            'http_status' => $response->status(),
            'body' => $response->json(),
        ]);

        if (! $response->successful()) {
            $errorMessage = $this->parseMetaError($response);

            Log::error('WhatsApp document template failed', [
                'phone' => $normalizedPhone,
                'http_status' => $response->status(),
                'error' => $errorMessage,
                'body' => $response->json(),
            ]);

            throw new RuntimeException($errorMessage);
        }

        $messageId = $response->json('messages.0.id');

        return [
            'success' => true,
            'message' => 'Document template sent via WhatsApp.',
            'message_id' => is_string($messageId) ? $messageId : null,
            'http_status' => $response->status(),
            'normalized_phone' => $normalizedPhone,
        ];
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

    public function resolveMimeType(string $filePath, ?string $mime = null, ?string $fileName = null): string
    {
        $candidates = array_values(array_filter([
            $mime,
            is_readable($filePath) ? (mime_content_type($filePath) ?: null) : null,
            $this->mimeTypeFromFileName($fileName ?? basename($filePath)),
        ], fn (?string $candidate): bool => is_string($candidate) && $candidate !== ''));

        foreach ($candidates as $candidate) {
            if ($this->isAllowedWhatsAppMime($candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(
            'Unsupported file type for WhatsApp. Allowed types include PDF, images, video, audio, and Office documents.',
        );
    }

    private function mimeTypeFromFileName(string $fileName): ?string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            '3gp', '3gpp' => 'video/3gpp',
            'aac' => 'audio/aac',
            'mp3' => 'audio/mpeg',
            'amr' => 'audio/amr',
            'ogg' => 'audio/ogg',
            'opus' => 'audio/opus',
            'm4a' => 'audio/mp4',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => null,
        };
    }

    private function isAllowedWhatsAppMime(string $mime): bool
    {
        if ($mime === 'application/octet-stream') {
            return false;
        }

        return in_array(strtolower($mime), [
            'audio/aac',
            'audio/mp4',
            'audio/mpeg',
            'audio/amr',
            'audio/ogg',
            'audio/opus',
            'application/vnd.ms-powerpoint',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/pdf',
            'text/plain',
            'application/vnd.ms-excel',
            'image/jpeg',
            'image/png',
            'image/webp',
            'video/mp4',
            'video/3gpp',
        ], true);
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
        $version = (string) config('whatsapp.graph_api_version', 'v25.0');
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
