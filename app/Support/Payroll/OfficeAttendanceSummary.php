<?php

namespace App\Support\Payroll;

use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class OfficeAttendanceSummary
{
    public function __construct(
        public readonly float $presentDays,
        public readonly float $absentDays,
        public readonly float $overtimeHours,
        public readonly int $lateMinutes,
        public readonly int $recordCount,
        public readonly int $workingDays,
    ) {}

    /**
     * @param  list<int>  $companyWorkingDays
     */
    public static function fromRecords(
        Collection $records,
        int $workingDaysInPeriod,
        array $companyWorkingDays,
    ): self {
        $allowedWorkingDays = $companyWorkingDays !== [] ? $companyWorkingDays : [1, 2, 3, 4, 5];
        $presentDays = 0.0;
        $overtimeHours = 0.0;
        $lateMinutes = 0;

        foreach ($records as $record) {
            /** @var AttendanceRecord $record */
            if ($record->status === AttendanceRecord::STATUS_WEEKEND) {
                continue;
            }

            $isWorkingDay = self::isWorkingDay($record->date, $allowedWorkingDays);

            $presentDays += match ($record->status) {
                AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE => 1.0,
                AttendanceRecord::STATUS_HALF_DAY => 0.5,
                AttendanceRecord::STATUS_HOLIDAY => $isWorkingDay ? 1.0 : 0.0,
                default => 0.0,
            };

            $overtimeHours += (float) ($record->overtime_hours ?? 0);

            if ($record->status === AttendanceRecord::STATUS_LATE) {
                $lateMinutes += (int) ($record->late_minutes ?? 0);
            }
        }

        $absentDays = max(0.0, $workingDaysInPeriod - $presentDays);

        return new self(
            presentDays: round($presentDays, 2),
            absentDays: round($absentDays, 2),
            overtimeHours: round($overtimeHours, 2),
            lateMinutes: $lateMinutes,
            recordCount: $records->count(),
            workingDays: $workingDaysInPeriod,
        );
    }

    /**
     * @param  list<int>  $companyWorkingDays
     */
    public static function empty(int $workingDaysInPeriod): self
    {
        return new self(
            presentDays: 0.0,
            absentDays: (float) $workingDaysInPeriod,
            overtimeHours: 0.0,
            lateMinutes: 0,
            recordCount: 0,
            workingDays: $workingDaysInPeriod,
        );
    }

    /**
     * @param  list<int>  $workingDays
     */
    private static function isWorkingDay(CarbonInterface $date, array $workingDays): bool
    {
        return in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $workingDays, true);
    }
}
