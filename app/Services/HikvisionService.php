<?php

namespace App\Services;

use App\Models\HikvisionSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HikvisionService
{
    /**
     * @param  array<string, mixed>|null  $override
     * @return array{api_host: string, api_key: string, api_secret: string}
     */
    public function resolveCredentials(?array $override = null): array
    {
        $override = $override ?? [];
        $stored = HikvisionSetting::current();

        $apiHost = (string) ($override['api_host'] ?? $stored->api_host ?? env('HIKVISION_API_HOST', ''));
        $apiKey = (string) ($override['api_key'] ?? $stored->api_key ?? env('HIKVISION_API_KEY', ''));
        $apiSecret = (string) ($override['api_secret'] ?? $stored->api_secret ?? env('HIKVISION_API_SECRET', ''));

        return [
            'api_host' => rtrim($apiHost, '/'),
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{access_token: string, expire_time: int, user_id: string, area_domain: string}
     */
    public function getAccessToken(?array $override = null): array
    {
        $credentials = $this->resolveCredentials($override);

        if ($credentials['api_host'] === '' || $credentials['api_key'] === '' || $credentials['api_secret'] === '') {
            throw new RuntimeException('Hikvision API credentials are not configured.');
        }

        $response = Http::timeout((int) config('hikvision.timeout', 20))
            ->acceptJson()
            ->post($credentials['api_host'].config('hikvision.token_path'), [
                'appKey' => $credentials['api_key'],
                'secretKey' => $credentials['api_secret'],
            ]);

        $payload = $response->json();

        if (! is_array($payload) || ($payload['errorCode'] ?? null) !== '0') {
            $message = is_array($payload) ? (string) ($payload['message'] ?? 'Hikvision authentication failed.') : 'Hikvision authentication failed.';

            throw new RuntimeException($message);
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data) || ! filled($data['accessToken'] ?? null)) {
            throw new RuntimeException('Hikvision authentication response was invalid.');
        }

        return [
            'access_token' => (string) $data['accessToken'],
            'expire_time' => (int) ($data['expireTime'] ?? 0),
            'user_id' => (string) ($data['userId'] ?? ''),
            'area_domain' => rtrim((string) ($data['areaDomain'] ?? $credentials['api_host']), '/'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{success: bool, message: string}
     */
    public function testConnection(?array $override = null): array
    {
        try {
            $token = $this->getAccessToken($override);

            return [
                'success' => true,
                'message' => 'Connection successful.',
            ];
        } catch (RuntimeException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{total_count: int, page_index: int, page_size: int, users: list<array{id: string, name: string}>}
     */
    public function getUsers(int $pageIndex = 1, int $pageSize = 50, ?array $override = null): array
    {
        $token = $this->getAccessToken($override);
        $host = $token['area_domain'] !== '' ? $token['area_domain'] : $this->resolveCredentials($override)['api_host'];

        $response = Http::timeout((int) config('hikvision.timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'Token' => $token['access_token'],
            ])
            ->post($host.config('hikvision.users_path'), [
                'pageIndex' => $pageIndex,
                'pageSize' => $pageSize,
            ]);

        $payload = $response->json();

        if (! is_array($payload) || ($payload['errorCode'] ?? null) !== '0') {
            $message = is_array($payload) ? (string) ($payload['message'] ?? 'Failed to fetch Hikvision users.') : 'Failed to fetch Hikvision users.';

            throw new RuntimeException($message);
        }

        $data = $payload['data'] ?? [];
        $users = [];

        foreach ($data['user'] ?? [] as $user) {
            if (! is_array($user)) {
                continue;
            }

            $users[] = [
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['name'] ?? ''),
            ];
        }

        return [
            'total_count' => (int) ($data['totalCount'] ?? 0),
            'page_index' => (int) ($data['pageIndex'] ?? $pageIndex),
            'page_size' => (int) ($data['pageSize'] ?? $pageSize),
            'users' => $users,
        ];
    }
}
