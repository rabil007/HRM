<?php

namespace App\Services;

use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionDevice;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
use App\Models\HikvisionSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HikvisionService
{
    /**
     * @var array{access_token: string, expire_time: int, user_id: string, area_domain: string}|null
     */
    private ?array $cachedAccessToken = null;

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{api_host: string, api_key: string, api_secret: string}
     */
    public function resolveCredentials(?array $override = null): array
    {
        $override = $override ?? [];
        $stored = HikvisionSetting::current();

        $apiHost = (string) ($override['api_host'] ?? $stored->api_host ?? config('hikvision.api_host', ''));
        $apiKey = (string) ($override['api_key'] ?? $stored->api_key ?? config('hikvision.api_key', ''));
        $apiSecret = (string) ($override['api_secret'] ?? $stored->api_secret ?? config('hikvision.api_secret', ''));

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
        if ($override !== null) {
            return $this->fetchAccessToken($override);
        }

        if (
            $this->cachedAccessToken !== null
            && ($this->cachedAccessToken['expire_time'] ?? 0) > time() + 60
        ) {
            return $this->cachedAccessToken;
        }

        $this->cachedAccessToken = $this->fetchAccessToken(null);

        return $this->cachedAccessToken;
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{access_token: string, expire_time: int, user_id: string, area_domain: string}
     */
    private function fetchAccessToken(?array $override): array
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

    /**
     * @return list<array<string, mixed>>
     */
    public function getPersonGroups(?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.person_groups_search_path'), [
            'parentGroupId' => '',
            'depthTraversal' => true,
        ], $override);

        $groups = $payload['data']['personGroupList'] ?? [];

        if (! is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, is_array(...)));
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{persons: list<array<string, mixed>>, page_index: int, page_size: int}
     */
    public function getPersons(int $pageIndex = 1, int $pageSize = 100, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.persons_list_path'), [
            'pageIndex' => $pageIndex,
            'pageSize' => $pageSize,
        ], $override);

        $persons = $payload['data']['personList'] ?? [];

        if (! is_array($persons)) {
            $persons = [];
        }

        return [
            'persons' => array_values(array_filter($persons, is_array(...))),
            'page_index' => $pageIndex,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @return array{synced_count: int, message: string}
     */
    public function syncPersonGroups(): array
    {
        $groups = $this->getPersonGroups();
        $syncedCount = 0;

        foreach ($groups as $group) {
            if ((string) ($group['groupId'] ?? '') === '') {
                continue;
            }

            HikvisionPersonGroup::upsertFromApi($group);
            $syncedCount++;
        }

        return [
            'synced_count' => $syncedCount,
            'message' => "Synced {$syncedCount} department(s).",
        ];
    }

    /**
     * @return array{synced_count: int, group_count: int, message: string}
     */
    public function syncPersons(): array
    {
        $this->ensureConfigured();

        $groupResult = $this->syncPersonGroups();
        $pageIndex = 1;
        $pageSize = (int) config('hikvision.persons_page_size', 100);
        $syncedCount = 0;

        do {
            $result = $this->getPersons($pageIndex, $pageSize);
            $persons = $result['persons'];

            foreach ($persons as $apiPerson) {
                $personInfo = is_array($apiPerson['personInfo'] ?? null) ? $apiPerson['personInfo'] : [];

                if ((string) ($personInfo['personId'] ?? '') === '') {
                    continue;
                }

                HikvisionPerson::upsertFromApi($apiPerson);
                $syncedCount++;
            }

            $pageIndex++;
        } while (count($persons) === $pageSize);

        HikvisionSetting::current()->update([
            'persons_last_synced_at' => now(),
        ]);

        return [
            'synced_count' => $syncedCount,
            'group_count' => $groupResult['synced_count'],
            'message' => "Synced {$syncedCount} person(s) and {$groupResult['synced_count']} department(s).",
        ];
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
        $certificateCount = $this->fetchCertificateRecords($startTime, $endTime);
        $totalCount = $fetchedCount + $mobileCount + $certificateCount;

        return [
            'fetched_count' => $totalCount,
            'message' => "Fetched {$totalCount} access record(s) for today ({$fetchedCount} device, {$mobileCount} mobile app, {$certificateCount} certificate).",
        ];
    }

    public function fetchCertificateRecords(
        CarbonInterface $startTime,
        CarbonInterface $endTime,
    ): int {
        $pageSize = (int) config('hikvision.certificate_records_page_size', 100);
        $pageIndex = 1;
        $storedCount = 0;

        do {
            $result = $this->searchCertificateRecords($startTime, $endTime, $pageIndex, $pageSize);
            $records = $result['records'];

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $stored = HikvisionAccessEvent::upsertFromCertificateRecord($record);

                if ($stored !== null) {
                    $storedCount++;
                }
            }

            $pageIndex++;
        } while (count($records) === $pageSize);

        return $storedCount;
    }

    /**
     * @return array{records: list<array<string, mixed>>, total: int, page_index: int, page_size: int}
     */
    public function searchCertificateRecords(
        CarbonInterface $beginTime,
        CarbonInterface $endTime,
        int $pageIndex = 1,
        int $pageSize = 100,
        ?array $override = null,
    ): array {
        $payload = $this->postWithToken(config('hikvision.certificate_records_search_path'), [
            'pageIndex' => $pageIndex,
            'pageSize' => $pageSize,
            'beginTime' => $beginTime->format('Y-m-d\TH:i:sP'),
            'endTime' => $endTime->format('Y-m-d\TH:i:sP'),
        ], $override);

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $records = $data['recordList'] ?? [];

        if (! is_array($records)) {
            $records = [];
        }

        return [
            'records' => array_values(array_filter($records, is_array(...))),
            'total' => (int) ($data['totalNum'] ?? 0),
            'page_index' => $pageIndex,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPersonDetail(string $personId, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.persons_get_path'), [
            'personId' => $personId,
        ], $override);

        $person = $payload['data']['personInfo'] ?? $payload['data'] ?? null;

        if (! is_array($person)) {
            throw new RuntimeException('Hikvision person detail response was invalid.');
        }

        return $person;
    }

    /**
     * @param  array<string, mixed>  $personInfo
     * @return array<string, mixed>
     */
    public function createPerson(array $personInfo, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.persons_add_path'), $personInfo, $override);

        return is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    }

    /**
     * @param  array<string, mixed>  $personInfo
     * @return array<string, mixed>
     */
    public function updatePerson(array $personInfo, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.persons_update_path'), $personInfo, $override);

        return is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    }

    public function deletePerson(string $personId, ?array $override = null): void
    {
        $this->postWithToken(config('hikvision.persons_delete_path'), [
            'personId' => $personId,
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadPersonPhoto(string $personId, string $photoBase64, ?array $override = null): array
    {
        $payload = $this->postWithToken(config('hikvision.persons_photo_path'), [
            'personId' => $personId,
            'photoData' => $photoBase64,
        ], $override);

        return is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function registerWebhook(string $callbackUrl, ?array $override = null): array
    {
        $settings = HikvisionSetting::current();
        $settings->ensureWebhookVerifyToken();

        $payload = [
            'callbackUrl' => $callbackUrl,
            'retryTimes' => 3,
            'retryDelay' => 1000,
        ];

        $signSecret = $settings->webhookSignSecretForRegistration();

        if ($signSecret !== null) {
            $payload['signSecret'] = $signSecret;
        }

        $this->postWithToken(config('hikvision.webhook_config_save_path'), $payload, $override);

        $this->postWithToken(config('hikvision.rawmsg_mq_subscribe_path'), [
            'subscribeType' => 1,
            'msgType' => [],
        ], $override);

        $settings->markWebhookRegistered($callbackUrl);

        return [
            'success' => true,
            'message' => 'Webhook registered successfully.',
        ];
    }

    /**
     * @return array{success: bool, message: string, callback_url: string}
     */
    public function ensureWebhookConfigured(string $callbackUrl): array
    {
        $settings = HikvisionSetting::current();
        $settings->ensureWebhookVerifyToken();

        if ($settings->webhook_registered_at !== null && $settings->webhook_callback_url === $callbackUrl) {
            return [
                'success' => true,
                'message' => 'Webhook is already registered.',
                'callback_url' => $callbackUrl,
            ];
        }

        return [
            ...$this->registerWebhook($callbackUrl),
            'callback_url' => $callbackUrl,
        ];
    }

    protected function ensureConfigured(): void
    {
        if (! HikvisionSetting::current()->isConfigured()) {
            throw new RuntimeException('Hikvision integration is not configured. Add credentials in Application settings.');
        }
    }
}
