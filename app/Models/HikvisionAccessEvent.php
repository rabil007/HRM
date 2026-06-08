<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HikvisionAccessEvent extends Model
{
    public const ATTENDANCE_CHECK_IN = 'checkIn';

    public const ATTENDANCE_CHECK_OUT = 'checkOut';

    public const TRANSACTION_DEVICE = 'device';

    public const TRANSACTION_MOBILE_APP = 'mobile_app';

    public const TRANSACTION_CORRECTION = 'correction';

    public const TRANSACTION_UNKNOWN = 'unknown';

    public const TRANSACTION_NOT_REQUIRED = 'not_required';

    public const EVENT_SOURCE_ACS_ISAPI = 'acs_isapi';

    public const EVENT_SOURCE_ATTENDANCE_API = 'attendance_api';

    public const EVENT_SOURCE_CERTIFICATE_API = 'certificate_api';

    public const EVENT_SOURCE_WEBHOOK = 'webhook';

    protected $fillable = [
        'system_id',
        'msg_type',
        'occurrence_time',
        'batch_id',
        'device_id',
        'device_name',
        'resource_id',
        'resource_name',
        'person_name',
        'person_hikvision_id',
        'door_no',
        'card_reader_no',
        'verify_mode',
        'attendance_status',
        'event_source',
        'transaction_source',
        'raw_payload',
        'snap_urls',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_time' => 'datetime',
            'raw_payload' => 'array',
            'snap_urls' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $acsEvent
     */
    public static function isAccessRecord(array $acsEvent): bool
    {
        $minor = (int) ($acsEvent['minor'] ?? 0);
        $ignoredMinors = config('hikvision.acs_ignored_minors', [21, 22, 23, 24]);

        if (in_array($minor, $ignoredMinors, true)) {
            return false;
        }

        $verifyMode = (string) ($acsEvent['currentVerifyMode'] ?? $acsEvent['verifyMode'] ?? '');

        if ($verifyMode === 'invalid') {
            return false;
        }

        $personName = trim((string) ($acsEvent['name'] ?? $acsEvent['employeeNoString'] ?? ''));
        $attendanceStatus = trim((string) ($acsEvent['attendanceStatus'] ?? ''));

        return $personName !== '' || $attendanceStatus !== '';
    }

    /**
     * @return list<string>
     */
    public static function attendanceStatusOptions(): array
    {
        return [
            self::ATTENDANCE_CHECK_IN,
            self::ATTENDANCE_CHECK_OUT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function deviceNameOptions(): array
    {
        return self::query()
            ->accessRecords()
            ->whereNotNull('device_name')
            ->where('device_name', '!=', '')
            ->distinct()
            ->orderBy('device_name')
            ->pluck('device_name')
            ->values()
            ->all();
    }

    /**
     * @param  array{search?: string, date_from?: string, date_to?: string, attendance_status?: string, device?: string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where('person_name', 'like', '%'.$search.'%');
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');

        if ($dateFrom !== '') {
            $query->whereDate('occurrence_time', '>=', $dateFrom);
        }

        $dateTo = (string) ($filters['date_to'] ?? '');

        if ($dateTo !== '') {
            $query->whereDate('occurrence_time', '<=', $dateTo);
        }

        $attendanceStatus = (string) ($filters['attendance_status'] ?? '');

        if ($attendanceStatus !== '' && in_array($attendanceStatus, self::attendanceStatusOptions(), true)) {
            $query->where('attendance_status', $attendanceStatus);
        }

        $device = trim((string) ($filters['device'] ?? ''));

        if ($device !== '') {
            $query->where('device_name', $device);
        }

        return $query;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAccessRecords(Builder $query): Builder
    {
        return $query
            ->whereIn('event_source', [
                self::EVENT_SOURCE_ACS_ISAPI,
                self::EVENT_SOURCE_ATTENDANCE_API,
                self::EVENT_SOURCE_CERTIFICATE_API,
                self::EVENT_SOURCE_WEBHOOK,
            ])
            ->where(function (Builder $query): void {
                $query->whereNotNull('person_name')
                    ->where('person_name', '!=', '')
                    ->orWhereNotNull('attendance_status')
                    ->where('attendance_status', '!=', '');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('verify_mode')
                    ->orWhere('verify_mode', '!=', 'invalid');
            });
    }

    public static function mapClockSource(int $source): string
    {
        return match ($source) {
            1 => self::TRANSACTION_DEVICE,
            2 => self::TRANSACTION_CORRECTION,
            3 => self::TRANSACTION_MOBILE_APP,
            4 => self::TRANSACTION_NOT_REQUIRED,
            default => self::TRANSACTION_UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromTimeCardRow(array $row, string $attendanceStatus): ?self
    {
        $isCheckIn = $attendanceStatus === self::ATTENDANCE_CHECK_IN;
        $clockDate = trim((string) ($isCheckIn ? ($row['clockInDate'] ?? '') : ($row['clockOutDate'] ?? '')));
        $clockTime = trim((string) ($isCheckIn ? ($row['clockInTime'] ?? '') : ($row['clockOutTime'] ?? '')));
        $clockSource = (int) ($isCheckIn ? ($row['clockInSource'] ?? 0) : ($row['clockOutSource'] ?? 0));
        $deviceName = trim((string) ($isCheckIn ? ($row['clockInDevice'] ?? '') : ($row['clockOutDevice'] ?? '')));

        if ($clockDate === '' || $clockTime === '') {
            return null;
        }

        $transactionSource = self::mapClockSource($clockSource);

        if ($transactionSource !== self::TRANSACTION_MOBILE_APP) {
            return null;
        }

        $personName = trim((string) ($row['fullName'] ?? ''));
        $personCode = trim((string) ($row['personCode'] ?? ''));

        if ($personName === '' && $personCode === '') {
            return null;
        }

        $occurrenceTime = self::parseTimeCardDateTime($clockDate, $clockTime);
        $identity = $personCode !== '' ? $personCode : $personName;

        $systemId = implode(':', [
            'attendance',
            $identity,
            $occurrenceTime->toIso8601String(),
            $attendanceStatus,
        ]);

        return self::query()->updateOrCreate(
            [
                'system_id' => $systemId,
                'occurrence_time' => $occurrenceTime,
                'msg_type' => 'attendance/totaltimecard',
            ],
            [
                'person_name' => $personName !== '' ? $personName : null,
                'device_name' => $deviceName !== '' ? $deviceName : 'Mobile App',
                'attendance_status' => $attendanceStatus,
                'event_source' => self::EVENT_SOURCE_ATTENDANCE_API,
                'transaction_source' => $transactionSource,
                'raw_payload' => $row,
                'fetched_at' => now(),
            ],
        );
    }

    protected static function parseTimeCardDateTime(string $date, string $time): Carbon
    {
        $normalizedDate = str_replace('/', '-', $date);
        $timezone = (string) config('app.timezone', 'UTC');

        try {
            return Carbon::parse("{$normalizedDate} {$time}", $timezone);
        } catch (\Throwable) {
            try {
                return Carbon::parse("{$normalizedDate} {$time}", $timezone);
            } catch (\Throwable) {
                return now($timezone);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $acsEvent
     */
    public static function upsertFromAcsEvent(
        array $acsEvent,
        string $deviceId,
        string $deviceName,
    ): ?self {
        if (! self::isAccessRecord($acsEvent)) {
            return null;
        }
        $major = (int) ($acsEvent['major'] ?? 0);
        $minor = (int) ($acsEvent['minor'] ?? 0);
        $occurrenceTime = self::parseOccurrenceTime((string) ($acsEvent['time'] ?? ''));
        $doorNo = isset($acsEvent['doorNo']) ? (string) $acsEvent['doorNo'] : null;
        $cardReaderNo = isset($acsEvent['cardReaderNo']) ? (string) $acsEvent['cardReaderNo'] : null;
        $personName = (string) ($acsEvent['name'] ?? $acsEvent['employeeNoString'] ?? '');
        $verifyMode = (string) ($acsEvent['currentVerifyMode'] ?? $acsEvent['verifyMode'] ?? '');
        $attendanceStatus = (string) ($acsEvent['attendanceStatus'] ?? '');

        $systemId = implode(':', [
            $deviceId,
            $occurrenceTime->toIso8601String(),
            (string) $major,
            (string) $minor,
            $cardReaderNo ?? '',
            $personName,
        ]);

        return self::query()->updateOrCreate(
            [
                'system_id' => $systemId,
                'occurrence_time' => $occurrenceTime,
                'msg_type' => "acs/{$major}/{$minor}",
            ],
            [
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'resource_name' => $doorNo !== null ? "Door {$doorNo}" : null,
                'person_name' => $personName !== '' ? $personName : null,
                'door_no' => $doorNo,
                'card_reader_no' => $cardReaderNo,
                'verify_mode' => $verifyMode !== '' ? $verifyMode : null,
                'attendance_status' => $attendanceStatus !== '' ? $attendanceStatus : null,
                'event_source' => self::EVENT_SOURCE_ACS_ISAPI,
                'transaction_source' => self::TRANSACTION_DEVICE,
                'raw_payload' => $acsEvent,
                'fetched_at' => now(),
            ],
        );
    }

    protected static function parseOccurrenceTime(string $value): Carbon
    {
        if ($value === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function upsertFromCertificateRecord(array $record): ?self
    {
        $personInfo = is_array($record['personInfo'] ?? null) ? $record['personInfo'] : [];
        $personName = trim((string) ($personInfo['personName'] ?? $personInfo['name'] ?? $record['personName'] ?? ''));
        $personHikvisionId = trim((string) ($personInfo['personId'] ?? $record['personId'] ?? ''));
        $occurrenceTime = self::parseOccurrenceTime((string) ($record['occurTime'] ?? $record['swipeTime'] ?? ''));
        $attendanceStatus = trim((string) ($record['attendanceStatus'] ?? ''));
        $deviceName = trim((string) ($record['deviceName'] ?? $record['elementName'] ?? ''));
        $verifyMode = trim((string) ($record['verifyMode'] ?? $record['checkType'] ?? ''));
        $recordId = trim((string) ($record['recordId'] ?? $record['id'] ?? ''));
        $transactionSource = self::mapCertificateRecordSource($record);

        if ($personName === '' && $attendanceStatus === '') {
            return null;
        }

        if (self::isDuplicateAccessRecord($personName, $occurrenceTime, $attendanceStatus, $transactionSource)) {
            return null;
        }

        $systemId = $recordId !== ''
            ? "cert:{$recordId}"
            : 'cert:'.hash('sha256', implode('|', [$personHikvisionId, $personName, $occurrenceTime->toIso8601String(), $attendanceStatus, $deviceName]));

        $snapUrls = self::extractSnapUrls($record);

        return self::query()->updateOrCreate(
            [
                'system_id' => $systemId,
                'occurrence_time' => $occurrenceTime,
                'msg_type' => 'acs/certificate-record',
            ],
            [
                'person_name' => $personName !== '' ? $personName : null,
                'person_hikvision_id' => $personHikvisionId !== '' ? $personHikvisionId : null,
                'device_name' => $deviceName !== '' ? $deviceName : null,
                'resource_name' => trim((string) ($record['elementName'] ?? '')) ?: null,
                'verify_mode' => $verifyMode !== '' ? $verifyMode : null,
                'attendance_status' => $attendanceStatus !== '' ? $attendanceStatus : null,
                'event_source' => self::EVENT_SOURCE_CERTIFICATE_API,
                'transaction_source' => $transactionSource,
                'snap_urls' => $snapUrls !== [] ? $snapUrls : null,
                'raw_payload' => $record,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function upsertFromWebhook(array $payload): ?self
    {
        if (isset($payload['list']) && is_array($payload['list'])) {
            $batchId = filled($payload['batchId'] ?? null) ? (string) $payload['batchId'] : null;
            $stored = null;

            foreach ($payload['list'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $stored = self::upsertFromHikConnectListItem($item, $batchId) ?? $stored;
            }

            return $stored;
        }

        if (isset($payload['recordList']) && is_array($payload['recordList'])) {
            $stored = null;

            foreach ($payload['recordList'] as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $stored = self::upsertFromWebhookRecord($record) ?? $stored;
            }

            return $stored;
        }

        return self::upsertFromWebhookRecord($payload);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function upsertFromHikConnectListItem(array $item, ?string $batchId = null): ?self
    {
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];
        $openDoorInfo = is_array($data['openDoorInfo'] ?? null) ? $data['openDoorInfo'] : [];
        $event = is_array($openDoorInfo['event'] ?? null) ? $openDoorInfo['event'] : [];
        $eventBasicInfo = is_array($event['basicInfo'] ?? null) ? $event['basicInfo'] : [];
        $intelliInfo = is_array($event['intelliInfo'] ?? null) ? $event['intelliInfo'] : [];
        $itemBasicInfo = is_array($item['basicInfo'] ?? null) ? $item['basicInfo'] : [];
        $device = is_array($itemBasicInfo['device'] ?? null) ? $itemBasicInfo['device'] : [];

        $firstName = trim((string) ($intelliInfo['firstName'] ?? ''));
        $lastName = trim((string) ($intelliInfo['lastName'] ?? ''));
        $personName = trim("{$firstName} {$lastName}") !== ''
            ? trim("{$firstName} {$lastName}")
            : $firstName;
        $personHikvisionId = trim((string) ($intelliInfo['personId'] ?? ''));
        $occurrenceTime = self::parseOccurrenceTime((string) (
            $itemBasicInfo['occurrenceTime']
            ?? $eventBasicInfo['occurTime']
            ?? ''
        ));
        $deviceName = trim((string) ($device['name'] ?? $eventBasicInfo['deviceName'] ?? ''));
        $deviceId = filled($device['id'] ?? null) ? (string) $device['id'] : (
            filled($eventBasicInfo['deviceId'] ?? null) ? (string) $eventBasicInfo['deviceId'] : null
        );
        $eventType = trim((string) ($itemBasicInfo['eventType'] ?? $eventBasicInfo['eventType'] ?? 'event'));
        $attendanceStatus = self::normalizeWebhookAttendanceStatus($intelliInfo['attendanceStatus'] ?? null);
        $systemId = trim((string) ($itemBasicInfo['systemId'] ?? $eventBasicInfo['systemId'] ?? ''));

        if ($personName === '' && $attendanceStatus === '') {
            return null;
        }

        if ($systemId === '') {
            $systemId = 'webhook:'.hash('sha256', json_encode($item));
        } else {
            $systemId = "webhook:{$systemId}";
        }

        return self::query()->updateOrCreate(
            [
                'system_id' => $systemId,
                'occurrence_time' => $occurrenceTime,
                'msg_type' => "webhook/event/{$eventType}",
            ],
            [
                'batch_id' => $batchId,
                'device_id' => $deviceId,
                'device_name' => $deviceName !== '' ? $deviceName : null,
                'person_name' => $personName !== '' ? $personName : null,
                'person_hikvision_id' => $personHikvisionId !== '' ? $personHikvisionId : null,
                'attendance_status' => $attendanceStatus !== '' ? $attendanceStatus : null,
                'event_source' => self::EVENT_SOURCE_WEBHOOK,
                'transaction_source' => self::TRANSACTION_DEVICE,
                'raw_payload' => $item,
                'fetched_at' => now(),
            ],
        );
    }

    protected static function normalizeWebhookAttendanceStatus(mixed $value): string
    {
        if (is_string($value)) {
            $normalized = trim($value);

            if (in_array($normalized, self::attendanceStatusOptions(), true)) {
                return $normalized;
            }

            if ($normalized === '0') {
                return self::ATTENDANCE_CHECK_IN;
            }

            if ($normalized === '1') {
                return self::ATTENDANCE_CHECK_OUT;
            }
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return match ((int) $value) {
                0 => self::ATTENDANCE_CHECK_IN,
                1 => self::ATTENDANCE_CHECK_OUT,
                default => '',
            };
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function upsertFromWebhookRecord(array $record): ?self
    {
        if (isset($record['personInfo']) || isset($record['occurTime']) || isset($record['swipeTime'])) {
            $stored = self::upsertFromCertificateRecord($record);

            if ($stored !== null) {
                $stored->update(['event_source' => self::EVENT_SOURCE_WEBHOOK]);
            }

            return $stored;
        }

        $basicInfo = is_array($record['basicInfo'] ?? null) ? $record['basicInfo'] : [];
        $device = is_array($basicInfo['device'] ?? null) ? $basicInfo['device'] : [];
        $occurrenceTime = self::parseOccurrenceTime((string) ($basicInfo['occurrenceTime'] ?? ''));
        $personName = trim((string) ($record['name'] ?? $record['personName'] ?? ''));
        $attendanceStatus = trim((string) ($record['attendanceStatus'] ?? ''));
        $deviceName = trim((string) ($device['name'] ?? $record['deviceName'] ?? ''));
        $systemId = trim((string) ($basicInfo['systemId'] ?? ''));

        if ($systemId === '') {
            $systemId = 'webhook:'.hash('sha256', json_encode($record));
        } else {
            $systemId = "webhook:{$systemId}";
        }

        if ($personName === '' && $attendanceStatus === '') {
            return null;
        }

        return self::query()->updateOrCreate(
            [
                'system_id' => $systemId,
                'occurrence_time' => $occurrenceTime,
                'msg_type' => (string) ($basicInfo['msgType'] ?? 'webhook/event'),
            ],
            [
                'device_id' => filled($device['id'] ?? null) ? (string) $device['id'] : null,
                'device_name' => $deviceName !== '' ? $deviceName : null,
                'person_name' => $personName !== '' ? $personName : null,
                'attendance_status' => $attendanceStatus !== '' ? $attendanceStatus : null,
                'event_source' => self::EVENT_SOURCE_WEBHOOK,
                'transaction_source' => self::TRANSACTION_DEVICE,
                'raw_payload' => $record,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected static function mapCertificateRecordSource(array $record): string
    {
        $sourceType = (int) ($record['sourceType'] ?? $record['recordSource'] ?? 0);

        return match ($sourceType) {
            3 => self::TRANSACTION_MOBILE_APP,
            1 => self::TRANSACTION_DEVICE,
            2 => self::TRANSACTION_CORRECTION,
            default => self::TRANSACTION_UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    protected static function extractSnapUrls(array $record): array
    {
        $urls = [];
        $snapList = $record['acsSnapPicList'] ?? $record['snapPicList'] ?? [];

        if (! is_array($snapList)) {
            return [];
        }

        foreach ($snapList as $snap) {
            if (! is_array($snap)) {
                continue;
            }

            $url = trim((string) ($snap['picUrl'] ?? $snap['url'] ?? ''));

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    protected static function isDuplicateAccessRecord(
        string $personName,
        Carbon $occurrenceTime,
        string $attendanceStatus,
        string $transactionSource,
    ): bool {
        if ($personName === '') {
            return false;
        }

        return self::query()
            ->where('person_name', $personName)
            ->where('occurrence_time', $occurrenceTime)
            ->where('attendance_status', $attendanceStatus !== '' ? $attendanceStatus : null)
            ->where('transaction_source', $transactionSource)
            ->exists();
    }
}
