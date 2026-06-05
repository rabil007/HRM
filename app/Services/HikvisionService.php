<?php

namespace App\Services;

use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionDevice;
use App\Models\HikvisionSetting;
use App\Models\HikvisionUser;
use Carbon\CarbonInterface;
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
            $this->getAccessToken($override);

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
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    public function postWithToken(string $path, array $body = [], ?array $override = null): array
    {
        $token = $this->getAccessToken($override);
        $host = $token['area_domain'] !== '' ? $token['area_domain'] : $this->resolveCredentials($override)['api_host'];

        $request = Http::timeout((int) config('hikvision.timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'Token' => $token['access_token'],
            ]);

        // Hikvision rejects an empty JSON array (`[]`) with "Input param error".
        $response = $body === []
            ? $request->withBody('{}', 'application/json')->post($host.$path)
            : $request->post($host.$path, $body);

        $payload = $response->json();

        if (! is_array($payload) || ($payload['errorCode'] ?? null) !== '0') {
            $message = is_array($payload) ? (string) ($payload['message'] ?? 'Hikvision API request failed.') : 'Hikvision API request failed.';

            throw new RuntimeException($message);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{total_count: int, page_index: int, page_size: int, users: list<array{id: string, name: string}>}
     */
    public function getUsers(int $pageIndex = 1, int $pageSize = 50, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.users_path'), [
            'pageIndex' => $pageIndex,
            'pageSize' => $pageSize,
        ], $override);

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

    /**
     * @return array{synced_count: int, message: string}
     */
    public function syncUsers(): array
    {
        $this->ensureConfigured();

        $pageIndex = 1;
        $pageSize = 50;
        $syncedCount = 0;
        $totalCount = null;

        do {
            $result = $this->getUsers($pageIndex, $pageSize);
            $totalCount = $result['total_count'];

            foreach ($result['users'] as $apiUser) {
                HikvisionUser::upsertFromApi($apiUser);
                $syncedCount++;
            }

            $pageIndex++;
        } while ($syncedCount < $totalCount && count($result['users']) > 0);

        return [
            'synced_count' => $syncedCount,
            'message' => "Synced {$syncedCount} Hikvision user(s).",
        ];
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{total_count: int, page_index: int, page_size: int, devices: list<array<string, mixed>>}
     */
    public function getDevices(int $pageIndex = 1, int $pageSize = 50, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.devices_path'), [
            'pageIndex' => $pageIndex,
            'pageSize' => $pageSize,
        ], $override);

        $data = $payload['data'] ?? [];
        $devices = [];

        foreach ($data['device'] ?? [] as $device) {
            if (! is_array($device)) {
                continue;
            }

            $devices[] = $device;
        }

        return [
            'total_count' => (int) ($data['totalCount'] ?? 0),
            'page_index' => (int) ($data['pageIndex'] ?? $pageIndex),
            'page_size' => (int) ($data['pageSize'] ?? $pageSize),
            'devices' => $devices,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    public function getDeviceDetail(string $deviceSerialNo, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.device_detail_path'), [
            'deviceSerialNo' => $deviceSerialNo,
        ], $override);

        $device = $payload['data']['device'] ?? null;

        if (! is_array($device)) {
            throw new RuntimeException('Hikvision device detail response was invalid.');
        }

        return $device;
    }

    /**
     * @return array{synced_count: int, message: string}
     */
    public function syncDevices(): array
    {
        $this->ensureConfigured();

        $pageIndex = 1;
        $pageSize = 50;
        $syncedCount = 0;
        $totalCount = null;

        do {
            $result = $this->getDevices($pageIndex, $pageSize);
            $totalCount = $result['total_count'];

            foreach ($result['devices'] as $apiDevice) {
                $serialNo = (string) ($apiDevice['serialNo'] ?? '');

                if ($serialNo === '') {
                    continue;
                }

                $detail = null;

                try {
                    $detail = $this->getDeviceDetail($serialNo);
                } catch (RuntimeException) {
                    // Keep list data even when detail fetch fails for a device.
                }

                HikvisionDevice::upsertFromApi($apiDevice, $detail);
                $syncedCount++;
            }

            $pageIndex++;
        } while ($syncedCount < $totalCount && count($result['devices']) > 0);

        return [
            'synced_count' => $syncedCount,
            'message' => "Synced {$syncedCount} Hikvision device(s).",
        ];
    }

    public function ensureMqSubscribed(): void
    {
        $settings = HikvisionSetting::current();

        if ($settings->mq_subscribed_at !== null) {
            return;
        }

        $this->postWithToken(config('hikvision.mq_subscribe_path'), [
            'subscribeType' => 1,
            'msgType' => [],
        ]);

        $settings->mq_subscribed_at = now();
        $settings->save();
    }

    /**
     * @return array{batch_id: string, remaining_number: int, events: list<array<string, mixed>>}
     */
    public function pollMessages(): array
    {
        $payload = $this->postWithToken(config('hikvision.mq_messages_path'));

        $data = $payload['data'] ?? [];
        $events = [];

        foreach ($data['event'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }

            $events[] = $event;
        }

        return [
            'batch_id' => (string) ($data['batchId'] ?? ''),
            'remaining_number' => (int) ($data['remainingNumber'] ?? 0),
            'events' => $events,
        ];
    }

    public function completeMessages(string $batchId): void
    {
        if ($batchId === '') {
            return;
        }

        $this->postWithToken(config('hikvision.mq_messages_complete_path'), [
            'batchId' => $batchId,
        ]);
    }

    /**
     * @return list<array{id: string, name: string, serial_no: string}>
     */
    public function getCachedAccessControllerDevices(): array
    {
        return HikvisionDevice::query()
            ->where('category', 'accessControllerDevice')
            ->orderBy('name')
            ->get()
            ->map(fn (HikvisionDevice $device): array => [
                'id' => (string) $device->hikvision_id,
                'name' => (string) $device->name,
                'serial_no' => (string) $device->serial_no,
            ])
            ->filter(fn (array $device): bool => $device['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, serial_no: string}>
     */
    public function getAccessControllerDevices(): array
    {
        $devices = [];
        $pageIndex = 1;
        $pageSize = 50;
        $totalCount = null;
        $fetched = 0;

        do {
            $payload = $this->postWithToken(config('hikvision.devices_path'), [
                'pageIndex' => $pageIndex,
                'pageSize' => $pageSize,
                'deviceCategory' => 'accessControllerDevice',
            ]);

            $data = $payload['data'] ?? [];
            $totalCount = (int) ($data['totalCount'] ?? 0);

            foreach ($data['device'] ?? [] as $device) {
                if (! is_array($device)) {
                    continue;
                }

                $devices[] = [
                    'id' => (string) ($device['id'] ?? ''),
                    'name' => (string) ($device['name'] ?? ''),
                    'serial_no' => (string) ($device['serialNo'] ?? ''),
                ];
                $fetched++;
            }

            $pageIndex++;
        } while ($fetched < $totalCount && count($data['device'] ?? []) > 0);

        return $devices;
    }

    /**
     * @return array<string, mixed>
     */
    public function isapiProxypass(string $deviceId, string $method, string $url, string $body = ''): array
    {
        $payload = $this->postWithToken(config('hikvision.isapi_proxypass_path'), [
            'method' => $method,
            'url' => $url,
            'id' => $deviceId,
            'contentType' => 'application/json',
            'body' => $body,
        ]);

        $data = $payload['data'] ?? null;

        if (! is_string($data)) {
            throw new RuntimeException('Hikvision ISAPI proxypass response was invalid.');
        }

        $decoded = json_decode($data, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Hikvision ISAPI proxypass returned non-JSON data.');
        }

        return $decoded;
    }

    public function fetchAcsEventsForDevice(
        string $deviceId,
        string $deviceName,
        CarbonInterface $startTime,
        CarbonInterface $endTime,
    ): int {
        $pageSize = (int) config('hikvision.acs_event_page_size', 50);
        $position = 0;
        $storedCount = 0;
        $totalMatches = null;

        do {
            $body = json_encode([
                'AcsEventCond' => [
                    'searchID' => '1',
                    'searchResultPosition' => $position,
                    'maxResults' => $pageSize,
                    'major' => 0,
                    'minor' => 0,
                    'startTime' => $startTime->format('Y-m-d\TH:i:sP'),
                    'endTime' => $endTime->format('Y-m-d\TH:i:sP'),
                ],
            ]);

            $decoded = $this->isapiProxypass(
                $deviceId,
                'POST',
                '/ISAPI/AccessControl/AcsEvent?format=json',
                $body !== false ? $body : '',
            );

            $acsEvent = $decoded['AcsEvent'] ?? [];
            $events = $acsEvent['InfoList'] ?? [];
            $totalMatches = (int) ($acsEvent['totalMatches'] ?? 0);

            if (! is_array($events)) {
                break;
            }

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $stored = HikvisionAccessEvent::upsertFromAcsEvent($event, $deviceId, $deviceName);

                if ($stored !== null) {
                    $storedCount++;
                }
            }

            $position += count($events);
        } while ($position < $totalMatches && count($events) > 0);

        return $storedCount;
    }

    public function fetchAttendanceMobileEvents(
        CarbonInterface $startTime,
        CarbonInterface $endTime,
    ): int {
        $pageSize = (int) config('hikvision.attendance_page_size', 200);
        $pageIndex = 1;
        $storedCount = 0;

        do {
            $payload = $this->postWithToken(config('hikvision.attendance_totaltimecard_path'), [
                'pageIndex' => $pageIndex,
                'pageSize' => $pageSize,
                'beginTime' => $startTime->format('Y-m-d\TH:i:sP'),
                'endTime' => $endTime->format('Y-m-d\TH:i:sP'),
                'dateFormat' => 'yyyy/MM/dd',
                'timeFormat' => 'HH:mm:ss',
                'durationFormat' => 'HH:MM',
            ]);

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $rows = is_array($data['reportDataList'] ?? null) ? $data['reportDataList'] : [];
            $moreData = (int) ($data['moreData'] ?? 0);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $checkIn = HikvisionAccessEvent::upsertFromTimeCardRow($row, HikvisionAccessEvent::ATTENDANCE_CHECK_IN);

                if ($checkIn !== null) {
                    $storedCount++;
                }

                $checkOut = HikvisionAccessEvent::upsertFromTimeCardRow($row, HikvisionAccessEvent::ATTENDANCE_CHECK_OUT);

                if ($checkOut !== null) {
                    $storedCount++;
                }
            }

            $pageIndex++;
        } while ($moreData === 1 && $rows !== []);

        return $storedCount;
    }

    /**
     * @return array{fetched_count: int, message: string}
     */
    public function fetchAccessEvents(): array
    {
        $this->ensureConfigured();

        $timezone = (string) config('app.timezone', 'UTC');
        $startTime = now($timezone)->startOfDay();
        $endTime = now($timezone)->endOfDay();

        $devices = $this->getCachedAccessControllerDevices();

        if ($devices === []) {
            $devices = $this->getAccessControllerDevices();
        }

        if ($devices === []) {
            throw new RuntimeException('No access controller devices found. Sync devices first or check Hik-Connect.');
        }

        $fetchedCount = 0;

        foreach ($devices as $device) {
            if ($device['id'] === '') {
                continue;
            }

            $fetchedCount += $this->fetchAcsEventsForDevice(
                $device['id'],
                $device['name'],
                $startTime,
                $endTime,
            );
        }

        $mobileCount = $this->fetchAttendanceMobileEvents($startTime, $endTime);
        $totalCount = $fetchedCount + $mobileCount;

        return [
            'fetched_count' => $totalCount,
            'message' => "Fetched {$totalCount} access record(s) for today ({$fetchedCount} device, {$mobileCount} mobile app).",
        ];
    }

    protected function ensureConfigured(): void
    {
        if (! HikvisionSetting::current()->isConfigured()) {
            throw new RuntimeException('Hikvision integration is not configured. Add credentials in Application settings.');
        }
    }
}
