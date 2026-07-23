<?php

namespace App\Support\Payroll;

use App\Models\CrewTimesheet;
use App\Models\Employee;

final class ValidateCrewTimesheetOperationalIntegrity
{
    public function handle(CrewTimesheet $timesheet, Employee $employee): ?string
    {
        $checks = [
            ['sign_on_standby_from', 'sign_on_standby_to', 'sign_on_standby_days', 'Sign-On Standby'],
            ['onsite_from', 'onsite_to', 'onsite_days', 'Onsite'],
            ['sign_off_standby_from', 'sign_off_standby_to', 'sign_off_standby_days', 'Sign-Off Standby'],
        ];

        foreach ($checks as [$fromKey, $toKey, $daysKey, $label]) {
            $from = $timesheet->{$fromKey};
            $to = $timesheet->{$toKey};
            $days = $timesheet->{$daysKey};

            if ($from !== null && $to === null) {
                return "{$employee->name} has {$label} start date without an end date.";
            }

            if ($from === null && $to !== null) {
                return "{$employee->name} has {$label} end date without a start date.";
            }

            if ($from !== null && $to !== null && $to->lt($from)) {
                return "{$employee->name} has an invalid {$label} date range.";
            }

            if ($days !== null && (float) $days < 0) {
                return "{$employee->name} has negative {$label} days.";
            }
        }

        if ($timesheet->unpaid_leave_days !== null && (float) $timesheet->unpaid_leave_days < 0) {
            return "{$employee->name} has negative unpaid leave days.";
        }

        $ranges = [];

        foreach ($checks as [$fromKey, $toKey, , $label]) {
            $from = $timesheet->{$fromKey};
            $to = $timesheet->{$toKey};

            if ($from !== null && $to !== null) {
                $ranges[] = [$from, $to, $label];
            }
        }

        for ($i = 0; $i < count($ranges); $i++) {
            for ($j = $i + 1; $j < count($ranges); $j++) {
                [$fromA, $toA, $labelA] = $ranges[$i];
                [$fromB, $toB, $labelB] = $ranges[$j];

                if ($fromA->lte($toB) && $fromB->lte($toA)) {
                    return "{$employee->name} has overlapping {$labelA} and {$labelB} date ranges.";
                }
            }
        }

        return null;
    }
}
