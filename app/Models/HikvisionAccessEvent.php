<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HikvisionAccessEvent extends Model
{
    public const ATTENDANCE_CHECK_IN = 'checkIn';

    public const ATTENDANCE_CHECK_OUT = 'checkOut';

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
     * @param  array{search?: string, date_from?: string, date_to?: string, attendance_status?: string}  $filters
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

        return $query;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAccessRecords(Builder $query): Builder
    {
        return $query
            ->where('event_source', 'acs_isapi')
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
                'event_source' => 'acs_isapi',
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
