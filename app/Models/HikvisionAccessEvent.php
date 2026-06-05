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
        'door_no',
        'card_reader_no',
        'verify_mode',
        'attendance_status',
        'event_source',
        'transaction_source',
        'raw_payload',
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
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $apiEvent
     */
    public static function upsertFromApi(array $apiEvent, string $batchId = ''): self
    {
        $basicInfo = is_array($apiEvent['basicInfo'] ?? null) ? $apiEvent['basicInfo'] : [];
        $device = is_array($basicInfo['device'] ?? null) ? $basicInfo['device'] : [];
        $resource = is_array($basicInfo['resource'] ?? null) ? $basicInfo['resource'] : [];

        $occurrenceTime = self::parseOccurrenceTime((string) ($basicInfo['occurrenceTime'] ?? ''));

        return self::query()->updateOrCreate(
            [
                'system_id' => (string) ($basicInfo['systemId'] ?? ''),
                'occurrence_time' => $occurrenceTime,
                'msg_type' => (string) ($basicInfo['msgType'] ?? ''),
            ],
            [
                'batch_id' => $batchId !== '' ? $batchId : null,
                'device_id' => (string) ($device['id'] ?? ''),
                'device_name' => (string) ($device['name'] ?? ''),
                'resource_id' => (string) ($resource['id'] ?? ''),
                'resource_name' => (string) ($resource['name'] ?? ''),
                'event_source' => 'mq',
                'raw_payload' => $apiEvent,
                'fetched_at' => now(),
            ],
        );
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
}
