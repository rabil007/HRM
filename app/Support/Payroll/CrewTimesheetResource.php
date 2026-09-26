<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use Carbon\CarbonInterface;

final class CrewTimesheetResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(?CrewTimesheet $timesheet, bool $includeFinancial = true): ?array
    {
        if ($timesheet === null) {
            return null;
        }

        $timesheet->loadMissing(['segments.assignment']);
        $signOnDays = (float) ($timesheet->sign_on_standby_days ?? 0);
        $signOffDays = (float) ($timesheet->sign_off_standby_days ?? 0);
        $onsiteDays = (float) ($timesheet->onsite_days ?? 0);
        $totalStandbyDays = round($signOnDays + $signOffDays, 2);
        $totalPayableDays = round($signOnDays + $signOffDays + $onsiteDays, 2);
        $segments = $timesheet->segments;
        $signOnSegmentCount = $segments->where('pay_category', CrewTimesheetPayCategory::SignOnStandby)->count();
        $onsiteSegmentCount = $segments->where('pay_category', CrewTimesheetPayCategory::Onsite)->count();
        $signOffSegmentCount = $segments->where('pay_category', CrewTimesheetPayCategory::SignOffStandby)->count();

        $payload = [
            'id' => $timesheet->id,
            'period_id' => $timesheet->period_id,
            'employee_id' => $timesheet->employee_id,
            'sign_on_standby_from' => $timesheet->sign_on_standby_from?->toDateString(),
            'sign_on_standby_to' => $timesheet->sign_on_standby_to?->toDateString(),
            'sign_on_standby_days' => $timesheet->sign_on_standby_days,
            'sign_on_standby_has_multiple_periods' => $signOnSegmentCount > 1,
            'onsite_from' => $timesheet->onsite_from?->toDateString(),
            'onsite_to' => $timesheet->onsite_to?->toDateString(),
            'onsite_days' => $timesheet->onsite_days,
            'onsite_has_multiple_periods' => $onsiteSegmentCount > 1,
            'sign_off_standby_from' => $timesheet->sign_off_standby_from?->toDateString(),
            'sign_off_standby_to' => $timesheet->sign_off_standby_to?->toDateString(),
            'sign_off_standby_days' => $timesheet->sign_off_standby_days,
            'sign_off_standby_has_multiple_periods' => $signOffSegmentCount > 1,
            'has_multiple_periods' => $segments->count() > 1
                || $signOnSegmentCount > 1
                || $onsiteSegmentCount > 1
                || $signOffSegmentCount > 1,
            'segments' => $segments->map(fn ($segment) => [
                'id' => $segment->id,
                'sequence' => $segment->sequence,
                'pay_category' => $segment->pay_category?->value,
                'pay_category_label' => $segment->pay_category?->label(),
                'from_date' => $segment->from_date?->toDateString(),
                'to_date' => $segment->to_date?->toDateString(),
                'days' => $segment->days,
                'source' => $segment->source?->value,
                'source_label' => $segment->source?->label(),
                'crew_assignment_id' => $segment->crew_assignment_id,
                'assignment_no' => $segment->assignment?->assignment_no,
                'crew_assignment_phase_id' => $segment->crew_assignment_phase_id,
                'remarks' => $segment->remarks,
            ])->values()->all(),
            'unpaid_leave_days' => $timesheet->unpaid_leave_days,
            'total_standby_days' => $totalStandbyDays,
            'total_payable_days' => $totalPayableDays,
            'overtime_hours' => $timesheet->overtime_hours,
            'remarks' => $timesheet->remarks,
            'source' => $timesheet->source?->value,
            'source_label' => $timesheet->source?->label(),
            'readiness_status' => self::readinessStatus($timesheet),
            'readiness_status_label' => self::readinessStatusLabel($timesheet),
            'is_operationally_locked' => false,
        ];

        if ($includeFinancial) {
            $payload['overtime_amount'] = $timesheet->overtime_amount;
            $payload['additional_amount'] = $timesheet->additional_amount;
            $payload['deduction_amount'] = $timesheet->deduction_amount;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toBoardRow(
        Employee $employee,
        ?CrewTimesheet $timesheet,
        int $periodId,
        CarbonInterface $asOf,
        bool $includeFinancial = true,
    ): array {
        $paymentMethod = $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer;
        $contract = $employee->currentContract;
        $salaryStructure = $contract?->resolvedSalaryStructure() ?? ContractSalaryStructure::Daily;
        $readiness = self::boardReadinessStatus($timesheet, $salaryStructure);

        $row = [
            'employee' => PayrollEmployeeIdentityResource::forEmployee($employee),
            'period_id' => $periodId,
            'timesheet' => self::toArray($timesheet, $includeFinancial),
            'is_filled' => $timesheet !== null,
            'operational_source' => self::operationalSource($timesheet, $salaryStructure),
            'operational_source_label' => self::operationalSourceLabel($timesheet, $salaryStructure),
            'readiness_status' => $readiness,
            'readiness_status_label' => self::boardReadinessStatusLabel($readiness),
            // Compatibility aliases for existing frontend filters until fully migrated.
            'approval_status' => $readiness,
            'approval_status_label' => self::boardReadinessStatusLabel($readiness),
            'salary_structure' => $salaryStructure->value,
        ];

        if ($includeFinancial) {
            $row['primary_account'] = EmployeePrimaryAccountResource::forEmployee($employee);
            $row['salary_payment_method'] = $paymentMethod->value;
            $row['salary_payment_method_label'] = $paymentMethod->label();
            $row['contract'] = $contract !== null
                ? app(ResolveContractRatesForPeriod::class)->handle($contract, $asOf)
                : null;
        } else {
            $row['primary_account'] = null;
            $row['salary_payment_method'] = null;
            $row['salary_payment_method_label'] = null;
            $row['contract'] = null;
        }

        return $row;
    }

    /**
     * @return 'ready'|'not_entered'|'not_applicable'
     */
    public static function boardReadinessStatus(
        ?CrewTimesheet $timesheet,
        ContractSalaryStructure $salaryStructure,
    ): string {
        if ($salaryStructure === ContractSalaryStructure::Monthly && $timesheet === null) {
            return 'not_applicable';
        }

        if ($timesheet === null) {
            return 'not_entered';
        }

        return 'ready';
    }

    public static function boardReadinessStatusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Ready',
            'not_applicable' => 'Not applicable',
            default => 'Not Entered',
        };
    }

    /**
     * @return 'ready'|'not_entered'
     */
    public static function readinessStatus(CrewTimesheet $timesheet): string
    {
        return 'ready';
    }

    public static function readinessStatusLabel(CrewTimesheet $timesheet): string
    {
        return 'Ready';
    }

    /**
     * @return 'crew_operations'|'import'|'manual'|'monthly_crew'|'not_entered'
     */
    public static function operationalSource(?CrewTimesheet $timesheet, ContractSalaryStructure $salaryStructure): string
    {
        if ($salaryStructure === ContractSalaryStructure::Monthly) {
            return 'monthly_crew';
        }

        if ($timesheet === null) {
            return 'not_entered';
        }

        return match ($timesheet->source?->value) {
            'crew_operations' => 'crew_operations',
            'import' => 'import',
            default => 'manual',
        };
    }

    public static function operationalSourceLabel(?CrewTimesheet $timesheet, ContractSalaryStructure $salaryStructure): string
    {
        return match (self::operationalSource($timesheet, $salaryStructure)) {
            'crew_operations' => 'Crew Assignments',
            'import' => 'Excel Import',
            'manual' => 'Manual',
            'monthly_crew' => 'Monthly Crew',
            'not_entered' => 'Not Entered',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function toEmployeeRow(Employee $employee, int $periodId): array
    {
        return [
            'employee' => PayrollEmployeeIdentityResource::forEmployee($employee),
            'period_id' => $periodId,
            'timesheet' => null,
            'is_filled' => false,
        ];
    }
}
