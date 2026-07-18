<?php

namespace App\Models;

use App\Support\Hikvision\HikvisionWebhookEventFields;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HikvisionAccessEvent extends Model
{
    use SoftDeletes;

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
        'company_id',
        'hikvision_person_id',
        'hikvision_device_id',
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
            'company_id' => 'integer',
            'hikvision_person_id' => 'integer',
            'hikvision_device_id' => 'integer',
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

    public static function isWithinFetchWindow(
        CarbonInterface $occurrenceTime,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): bool {
        return $occurrenceTime->betweenIncluded($windowStart, $windowEnd);
    }

    /**
     * @param  array<string, mixed>  $acsEvent
     */
    public static function acsEventIsWithinFetchWindow(
        array $acsEvent,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): bool {
        if (! self::isAccessRecord($acsEvent)) {
            return false;
        }

        $occurrenceTime = self::parseOccurrenceTime((string) ($acsEvent['time'] ?? ''));

        return self::isWithinFetchWindow($occurrenceTime, $windowStart, $windowEnd);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function timeCardRowIsWithinFetchWindow(
        array $row,
        string $attendanceStatus,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): bool {
        $isCheckIn = $attendanceStatus === self::ATTENDANCE_CHECK_IN;
        $clockDate = trim((string) ($isCheckIn ? ($row['clockInDate'] ?? '') : ($row['clockOutDate'] ?? '')));
        $clockTime = trim((string) ($isCheckIn ? ($row['clockInTime'] ?? '') : ($row['clockOutTime'] ?? '')));
        $clockSource = (int) ($isCheckIn ? ($row['clockInSource'] ?? 0) : ($row['clockOutSource'] ?? 0));

        if ($clockDate === '' || $clockTime === '') {
            return false;
        }

        if (self::mapClockSource($clockSource) !== self::TRANSACTION_MOBILE_APP) {
            return false;
        }

        $personName = trim((string) ($row['fullName'] ?? ''));
        $personCode = trim((string) ($row['personCode'] ?? ''));

        if ($personName === '' && $personCode === '') {
            return false;
        }

        $occurrenceTime = self::parseTimeCardDateTime($clockDate, $clockTime);

        return self::isWithinFetchWindow($occurrenceTime, $windowStart, $windowEnd);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function certificateRecordIsWithinFetchWindow(
        array $record,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): bool {
        if (! self::isStorableCertificateRecord($record)) {
            return false;
        }

        $occurrenceTime = self::parseCertificateOccurrenceTime($record);

        return self::isWithinFetchWindow($occurrenceTime, $windowStart, $windowEnd);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function isStorableCertificateRecord(array $record): bool
    {
        $identity = self::resolveCertificatePersonIdentity($record);

        return $identity['name'] !== '' || $identity['id'] !== '';
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{name: string, id: string}
     */
    public static function resolveCertificatePersonIdentity(array $record): array
    {
        $personInfo = is_array($record['personInfo'] ?? null) ? $record['personInfo'] : [];
        $baseInfo = is_array($personInfo['baseInfo'] ?? null) ? $personInfo['baseInfo'] : [];
        $personName = trim((string) ($personInfo['personName'] ?? $personInfo['name'] ?? $record['personName'] ?? ''));

        if ($personName === '') {
            $firstName = trim((string) ($baseInfo['firstName'] ?? ''));
            $lastName = trim((string) ($baseInfo['lastName'] ?? ''));
            $personName = trim("{$firstName} {$lastName}");
        }

        $personHikvisionId = trim((string) ($personInfo['personId'] ?? $personInfo['id'] ?? $record['personId'] ?? ''));

        return [
            'name' => $personName,
            'id' => $personHikvisionId,
        ];
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
    public static function deviceNameOptions(int $companyId): array
    {
        return self::query()
            ->forCompany($companyId)
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
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
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
    public static function upsertFromTimeCardRow(
        int $companyId,
        array $row,
        string $attendanceStatus,
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
    ): ?self {
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

        if ($windowStart !== null && $windowEnd !== null && ! self::isWithinFetchWindow($occurrenceTime, $windowStart, $windowEnd)) {
            return null;
        }

        $personHikvisionId = self::resolveTimeCardPersonHikvisionId($companyId, $row, $personCode, $personName);
        $identity = $personCode !== '' ? $personCode : $personName;

        $systemId = implode(':', [
            'attendance',
            $identity,
            $occurrenceTime->toIso8601String(),
            $attendanceStatus,
        ]);

        return self::updateOrCreateIncludingTrashed(
            [
                'company_id' => $companyId,
                'system_id' => $systemId,
            ],
            [
                ...self::relatedModelIds($companyId, $personHikvisionId, null, null),
                'occurrence_time' => $occurrenceTime,
                'msg_type' => 'attendance/totaltimecard',
                'person_name' => $personName !== '' ? $personName : null,
                'person_hikvision_id' => $personHikvisionId !== '' ? $personHikvisionId : null,
                'device_name' => $deviceName !== '' ? $deviceName : 'Mobile App',
                'attendance_status' => $attendanceStatus,
                'event_source' => self::EVENT_SOURCE_ATTENDANCE_API,
                'transaction_source' => $transactionSource,
                'raw_payload' => $row,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function resolveTimeCardPersonHikvisionId(int $companyId, array $row, string $personCode, string $personName): string
    {
        $personId = trim((string) ($row['personId'] ?? ''));

        if ($personId !== '') {
            return $personId;
        }

        if ($personCode !== '') {
            $resolvedByCode = HikvisionPerson::query()
                ->forCompany($companyId)
                ->where('person_code', $personCode)
                ->value('person_id');

            if (filled($resolvedByCode)) {
                return (string) $resolvedByCode;
            }
        }

        if ($personName !== '') {
            $resolvedByName = HikvisionPerson::query()
                ->forCompany($companyId)
                ->whereRaw('LOWER(full_name) = ?', [mb_strtolower($personName)])
                ->value('person_id');

            if (filled($resolvedByName)) {
                return (string) $resolvedByName;
            }
        }

        return '';
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
        int $companyId,
        array $acsEvent,
        string $deviceId,
        string $deviceName,
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
    ): ?self {
        if (! self::isAccessRecord($acsEvent)) {
            return null;
        }
        $major = (int) ($acsEvent['major'] ?? 0);
        $minor = (int) ($acsEvent['minor'] ?? 0);
        $occurrenceTime = self::parseOccurrenceTime((string) ($acsEvent['time'] ?? ''));
        if ($windowStart !== null && $windowEnd !== null && ! self::isWithinFetchWindow($occurrenceTime, $windowStart, $windowEnd)) {
            return null;
        }
        $doorNo = isset($acsEvent['doorNo']) ? (string) $acsEvent['doorNo'] : null;
        $cardReaderNo = isset($acsEvent['cardReaderNo']) ? (string) $acsEvent['cardReaderNo'] : null;
        $personName = (string) ($acsEvent['name'] ?? $acsEvent['employeeNoString'] ?? '');
        $verifyMode = (string) ($acsEvent['currentVerifyMode'] ?? $acsEvent['verifyMode'] ?? '');
        $attendanceStatus = (string) ($acsEvent['attendanceStatus'] ?? '');
        $serialNo = isset($acsEvent['serialNo']) ? (string) $acsEvent['serialNo'] : '';

        if ($serialNo !== '') {
            $existing = self::findByDeviceAndSerialNo($companyId, $deviceId, $serialNo);

            if ($existing !== null) {
                $existing->update([
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
                ]);

                return $existing->refresh();
            }
        }

        $systemId = implode(':', [
            $deviceId,
            $occurrenceTime->toIso8601String(),
            (string) $major,
            (string) $minor,
            $cardReaderNo ?? '',
            $personName,
        ]);

        return self::updateOrCreateIncludingTrashed(
            [
                'company_id' => $companyId,
                'system_id' => $systemId,
            ],
            [
                ...self::relatedModelIds($companyId, null, $deviceId, null),
                'occurrence_time' => $occurrenceTime,
                'msg_type' => "acs/{$major}/{$minor}",
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
    protected static function parseCertificateOccurrenceTime(array $record): Carbon
    {
        $deviceTime = trim((string) ($record['deviceTime'] ?? ''));

        if ($deviceTime !== '') {
            return self::parseOccurrenceTime($deviceTime);
        }

        return self::parseOccurrenceTime((string) ($record['occurTime'] ?? $record['swipeTime'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected static function normalizeCertificateAttendanceStatus(array $record): string
    {
        $attendanceStatus = $record['attendanceStatus'] ?? '';

        if (is_int($attendanceStatus) || is_float($attendanceStatus)) {
            return match ((int) $attendanceStatus) {
                1 => self::ATTENDANCE_CHECK_IN,
                0 => self::ATTENDANCE_CHECK_OUT,
                default => '',
            };
        }

        return trim((string) $attendanceStatus);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function upsertFromCertificateRecord(
        int $companyId,
        array $record,
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
    ): ?self {
        if (! self::isStorableCertificateRecord($record)) {
            return null;
        }

        $identity = self::resolveCertificatePersonIdentity($record);
        $personName = $identity['name'];
        $personHikvisionId = $identity['id'];
        $occurrenceTime = self::parseCertificateOccurrenceTime($record);
        if ($windowStart !== null && $windowEnd !== null && ! self::isWithinFetchWindow($occurrenceTime, $windowStart, $windowEnd)) {
            return null;
        }
        $attendanceStatus = self::normalizeCertificateAttendanceStatus($record);
        $deviceName = trim((string) ($record['deviceName'] ?? $record['elementName'] ?? ''));
        $verifyMode = trim((string) ($record['verifyMode'] ?? $record['checkType'] ?? ''));
        $recordId = trim((string) ($record['recordId'] ?? $record['id'] ?? ''));
        $transactionSource = self::mapCertificateRecordSource($record);

        if (self::isDuplicateAccessRecord($companyId, $personName, $occurrenceTime, $attendanceStatus, $transactionSource)) {
            return null;
        }

        $systemId = $recordId !== ''
            ? "cert:{$recordId}"
            : 'cert:'.hash('sha256', implode('|', [$personHikvisionId, $personName, $occurrenceTime->toIso8601String(), $attendanceStatus, $deviceName]));

        $snapUrls = self::extractSnapUrls($record);

        return self::updateOrCreateIncludingTrashed(
            [
                'company_id' => $companyId,
                'system_id' => $systemId,
            ],
            [
                ...self::relatedModelIds($companyId, $personHikvisionId, null, $deviceName),
                'occurrence_time' => $occurrenceTime,
                'msg_type' => 'acs/certificate-record',
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
    public static function upsertFromWebhook(array $payload, int $companyId): ?self
    {
        if (isset($payload['list']) && is_array($payload['list'])) {
            $batchId = filled($payload['batchId'] ?? null) ? (string) $payload['batchId'] : null;
            $stored = null;

            foreach ($payload['list'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $stored = self::upsertFromHikConnectListItem($companyId, $item, $batchId) ?? $stored;
            }

            return $stored;
        }

        if (isset($payload['recordList']) && is_array($payload['recordList'])) {
            $stored = null;

            foreach ($payload['recordList'] as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $stored = self::upsertFromWebhookRecord($companyId, $record) ?? $stored;
            }

            return $stored;
        }

        return self::upsertFromWebhookRecord($companyId, $payload);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function upsertFromHikConnectListItem(int $companyId, array $item, ?string $batchId = null): ?self
    {
        $itemBasicInfo = is_array($item['basicInfo'] ?? null) ? $item['basicInfo'] : [];
        $device = is_array($itemBasicInfo['device'] ?? null) ? $itemBasicInfo['device'] : [];
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];
        ['eventBasicInfo' => $eventBasicInfo, 'intelliInfo' => $intelliInfo] = self::resolveOpenDoorEventParts($data);

        if (! self::isSuccessfulWebhookAuthentication($intelliInfo, $item)) {
            return null;
        }

        $person = self::resolveWebhookPersonIdentity($companyId, $intelliInfo, $data, $item);
        $personName = $person['person_name'];
        $personHikvisionId = $person['person_hikvision_id'];
        $occurrenceTime = self::parseOccurrenceTime((string) (
            $itemBasicInfo['occurrenceTime']
            ?? $eventBasicInfo['occurTime']
            ?? ''
        ));
        $deviceName = trim((string) ($device['name'] ?? $eventBasicInfo['deviceName'] ?? $eventBasicInfo['elementName'] ?? ''));
        $deviceId = filled($device['id'] ?? null) ? (string) $device['id'] : (
            filled($eventBasicInfo['deviceId'] ?? null) ? (string) $eventBasicInfo['deviceId'] : null
        );
        $eventType = trim((string) ($itemBasicInfo['eventType'] ?? $eventBasicInfo['eventType'] ?? 'event'));
        $attendanceStatus = self::normalizeOpenDoorAttendanceStatus(
            $intelliInfo['attendanceStatus'] ?? $item['attendanceStatus'] ?? null,
        );
        $serialNo = HikvisionWebhookEventFields::resolveSerialNo($eventBasicInfo);
        $fields = HikvisionWebhookEventFields::resolve($eventBasicInfo, $intelliInfo, $deviceName);

        if ($personName === '' && $attendanceStatus === '') {
            return null;
        }

        if ($serialNo !== null && $deviceId !== null) {
            $existing = self::findByDeviceAndSerialNo($companyId, $deviceId, $serialNo);

            if ($existing !== null) {
                $existing->update([
                    'batch_id' => $batchId,
                    'person_hikvision_id' => $personHikvisionId !== '' ? $personHikvisionId : $existing->person_hikvision_id,
                    'snap_urls' => $fields['snap_urls'] !== [] ? $fields['snap_urls'] : $existing->snap_urls,
                    'fetched_at' => now(),
                ]);

                return $existing->refresh();
            }

            $systemId = "webhook:{$deviceId}:{$serialNo}";
        } else {
            $areaSystemId = trim((string) ($itemBasicInfo['systemId'] ?? $eventBasicInfo['systemId'] ?? ''));

            $systemId = $areaSystemId !== ''
                ? "webhook:{$areaSystemId}:{$occurrenceTime->getTimestamp()}"
                : 'webhook:'.hash('sha256', json_encode($item));
        }

        return self::updateOrCreateIncludingTrashed(
            [
                'company_id' => $companyId,
                'system_id' => $systemId,
            ],
            [
                ...self::relatedModelIds($companyId, $personHikvisionId, $deviceId, $deviceName),
                'occurrence_time' => $occurrenceTime,
                'msg_type' => "webhook/event/{$eventType}",
                'batch_id' => $batchId,
                'device_id' => $deviceId,
                'device_name' => $deviceName !== '' ? $deviceName : null,
                'resource_name' => $fields['resource_name'],
                'door_no' => $fields['door_no'],
                'card_reader_no' => $fields['card_reader_no'],
                'person_name' => $personName !== '' ? $personName : null,
                'person_hikvision_id' => $personHikvisionId !== '' ? $personHikvisionId : null,
                'verify_mode' => $fields['verify_mode'],
                'attendance_status' => $attendanceStatus !== '' ? $attendanceStatus : null,
                'event_source' => self::EVENT_SOURCE_WEBHOOK,
                'transaction_source' => self::TRANSACTION_DEVICE,
                'raw_payload' => $item,
                'snap_urls' => $fields['snap_urls'] !== [] ? $fields['snap_urls'] : null,
                'fetched_at' => now(),
            ],
        );
    }

    public static function findByDeviceAndSerialNo(int $companyId, string $deviceId, string $serialNo): ?self
    {
        return self::query()
            ->forCompany($companyId)
            ->where('device_id', $deviceId)
            ->orderByDesc('id')
            ->get()
            ->first(fn (self $event): bool => self::extractSerialNoFromPayload($event->raw_payload) === $serialNo);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function extractSerialNoFromPayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (isset($payload['serialNo'])) {
            return (string) $payload['serialNo'];
        }

        $nested = $payload['data']['openDoorInfo']['event']['basicInfo']['serialNo'] ?? null;

        return $nested !== null ? (string) $nested : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{eventBasicInfo: array<string, mixed>, intelliInfo: array<string, mixed>}
     */
    protected static function resolveOpenDoorEventParts(array $data): array
    {
        $openDoorInfo = is_array($data['openDoorInfo'] ?? null) ? $data['openDoorInfo'] : [];

        if ($openDoorInfo === []) {
            return [
                'eventBasicInfo' => [],
                'intelliInfo' => [],
            ];
        }

        if (isset($openDoorInfo['event']) && is_array($openDoorInfo['event'])) {
            $event = $openDoorInfo['event'];
        } elseif (isset($openDoorInfo['basicInfo']) || isset($openDoorInfo['intelliInfo'])) {
            $event = $openDoorInfo;
        } else {
            $event = [];
        }

        return [
            'eventBasicInfo' => is_array($event['basicInfo'] ?? null) ? $event['basicInfo'] : [],
            'intelliInfo' => is_array($event['intelliInfo'] ?? null) ? $event['intelliInfo'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $intelliInfo
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $item
     * @return array{person_name: string, person_hikvision_id: string}
     */
    protected static function resolveWebhookPersonIdentity(int $companyId, array $intelliInfo, array $data, array $item): array
    {
        ['eventBasicInfo' => $eventBasicInfo, 'intelliInfo' => $nestedIntelliInfo] = self::resolveOpenDoorEventParts($data);
        $intelliInfo = $intelliInfo !== [] ? $intelliInfo : $nestedIntelliInfo;

        $personInfo = is_array($intelliInfo['personInfo'] ?? null) ? $intelliInfo['personInfo'] : (
            is_array($item['personInfo'] ?? null) ? $item['personInfo'] : []
        );

        $firstName = trim((string) ($intelliInfo['firstName'] ?? $personInfo['firstName'] ?? ''));
        $lastName = trim((string) ($intelliInfo['lastName'] ?? $personInfo['lastName'] ?? ''));
        $personName = trim("{$firstName} {$lastName}") !== ''
            ? trim("{$firstName} {$lastName}")
            : $firstName;

        if ($personName === '') {
            $personName = trim((string) (
                $intelliInfo['name']
                ?? $intelliInfo['personName']
                ?? $personInfo['personName']
                ?? $personInfo['name']
                ?? $eventBasicInfo['name']
                ?? ''
            ));
        }

        $personHikvisionId = trim((string) (
            $intelliInfo['personId']
            ?? $personInfo['personId']
            ?? ''
        ));

        if ($personName === '' && $personHikvisionId !== '') {
            $syncedPerson = HikvisionPerson::query()
                ->forCompany($companyId)
                ->where('person_id', $personHikvisionId)
                ->first();

            if ($syncedPerson !== null) {
                $personName = trim((string) ($syncedPerson->full_name ?? ''));

                if ($personName === '') {
                    $personName = trim(trim((string) ($syncedPerson->first_name ?? '')).' '.trim((string) ($syncedPerson->last_name ?? '')));
                }
            }
        }

        return [
            'person_name' => $personName,
            'person_hikvision_id' => $personHikvisionId,
        ];
    }

    /**
     * @param  array<string, mixed>  $intelliInfo
     * @param  array<string, mixed>  $record
     */
    protected static function isSuccessfulWebhookAuthentication(array $intelliInfo, array $record): bool
    {
        if (array_key_exists('authResult', $intelliInfo)) {
            return (int) $intelliInfo['authResult'] === 1;
        }

        if (isset($record['personInfo']) || isset($record['occurTime']) || isset($record['swipeTime'])) {
            return true;
        }

        return false;
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

    protected static function normalizeOpenDoorAttendanceStatus(mixed $value): string
    {
        if (is_string($value)) {
            $normalized = trim($value);

            if (in_array($normalized, self::attendanceStatusOptions(), true)) {
                return $normalized;
            }
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return match ((int) $value) {
                0, 1 => self::ATTENDANCE_CHECK_IN,
                2 => self::ATTENDANCE_CHECK_OUT,
                default => '',
            };
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function upsertFromWebhookRecord(int $companyId, array $record): ?self
    {
        if (isset($record['personInfo']) || isset($record['occurTime']) || isset($record['swipeTime'])) {
            $stored = self::upsertFromCertificateRecord($companyId, $record);

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

        return self::updateOrCreateIncludingTrashed(
            [
                'company_id' => $companyId,
                'system_id' => $systemId,
            ],
            [
                ...self::relatedModelIds(
                    $companyId,
                    null,
                    filled($device['id'] ?? null) ? (string) $device['id'] : null,
                    $deviceName,
                ),
                'occurrence_time' => $occurrenceTime,
                'msg_type' => (string) ($basicInfo['msgType'] ?? 'webhook/event'),
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
        int $companyId,
        string $personName,
        Carbon $occurrenceTime,
        string $attendanceStatus,
        string $transactionSource,
    ): bool {
        if ($personName === '') {
            return false;
        }

        return self::query()
            ->forCompany($companyId)
            ->where('person_name', $personName)
            ->where('occurrence_time', $occurrenceTime)
            ->where('attendance_status', $attendanceStatus !== '' ? $attendanceStatus : null)
            ->where('transaction_source', $transactionSource)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    protected static function updateOrCreateIncludingTrashed(array $attributes, array $values): self
    {
        $event = self::withTrashed()->updateOrCreate($attributes, $values);

        if ($event->trashed()) {
            $event->restore();
        }

        return $event;
    }

    /**
     * @return array{hikvision_person_id: int|null, hikvision_device_id: int|null}
     */
    protected static function relatedModelIds(
        int $companyId,
        ?string $personHikvisionId,
        ?string $deviceHikvisionId,
        ?string $deviceName,
    ): array {
        $personId = filled($personHikvisionId)
            ? HikvisionPerson::query()->forCompany($companyId)->where('person_id', $personHikvisionId)->value('id')
            : null;

        $deviceQuery = HikvisionDevice::query()->forCompany($companyId);

        if (filled($deviceHikvisionId)) {
            $deviceQuery->where('hikvision_id', $deviceHikvisionId);
        } elseif (filled($deviceName)) {
            $deviceQuery->where('name', $deviceName);
        } else {
            return [
                'hikvision_person_id' => $personId,
                'hikvision_device_id' => null,
            ];
        }

        return [
            'hikvision_person_id' => $personId,
            'hikvision_device_id' => $deviceQuery->value('id'),
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<HikvisionPerson, $this>
     */
    public function hikvisionPerson(): BelongsTo
    {
        return $this->belongsTo(HikvisionPerson::class);
    }

    /**
     * @return BelongsTo<HikvisionDevice, $this>
     */
    public function hikvisionDevice(): BelongsTo
    {
        return $this->belongsTo(HikvisionDevice::class);
    }
}
