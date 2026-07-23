<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use Carbon\CarbonInterface;

final class CrewTimesheetResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(?CrewTimesheet $timesheet): ?array
    {
        if ($timesheet === null) {
            return null;
        }

        $timesheet->loadMissing('preparation');
        $operationallyLocked = $timesheet->isOperationallyLocked();
        $signOnDays = (float) ($timesheet->sign_on_standby_days ?? 0);
        $signOffDays = (float) ($timesheet->sign_off_standby_days ?? 0);
        $onsiteDays = (float) ($timesheet->onsite_days ?? 0);
        $totalStandbyDays = round($signOnDays + $signOffDays, 2);
        $totalPayableDays = round($signOnDays + $signOffDays + $onsiteDays, 2);

        return [
            'id' => $timesheet->id,
            'period_id' => $timesheet->period_id,
            'employee_id' => $timesheet->employee_id,
            'sign_on_standby_from' => $timesheet->sign_on_standby_from?->toDateString(),
            'sign_on_standby_to' => $timesheet->sign_on_standby_to?->toDateString(),
            'sign_on_standby_days' => $timesheet->sign_on_standby_days,
            'onsite_from' => $timesheet->onsite_from?->toDateString(),
            'onsite_to' => $timesheet->onsite_to?->toDateString(),
            'onsite_days' => $timesheet->onsite_days,
            'sign_off_standby_from' => $timesheet->sign_off_standby_from?->toDateString(),
            'sign_off_standby_to' => $timesheet->sign_off_standby_to?->toDateString(),
            'sign_off_standby_days' => $timesheet->sign_off_standby_days,
            'unpaid_leave_days' => $timesheet->unpaid_leave_days,
            'total_standby_days' => $totalStandbyDays,
            'total_payable_days' => $totalPayableDays,
            'overtime_hours' => $timesheet->overtime_hours,
            'overtime_amount' => $timesheet->overtime_amount,
            'additional_amount' => $timesheet->additional_amount,
            'deduction_amount' => $timesheet->deduction_amount,
            'remarks' => $timesheet->remarks,
            'source' => $timesheet->source?->value,
            'source_label' => $timesheet->source?->label(),
            'approval_status' => $timesheet->approval_status?->value,
            'approval_status_label' => self::approvalStatusLabel($timesheet),
            'return_reason' => $timesheet->return_reason,
            'submitted_at' => $timesheet->submitted_at?->toIso8601String(),
            'approved_at' => $timesheet->approved_at?->toIso8601String(),
            'returned_at' => $timesheet->returned_at?->toIso8601String(),
            'crew_timesheet_preparation_id' => $timesheet->crew_timesheet_preparation_id,
            'operational_approved_by' => $timesheet->operational_approved_by,
            'operational_approved_at' => $timesheet->operational_approved_at?->toIso8601String(),
            'movement_source_hash' => $timesheet->movement_source_hash,
            'is_operationally_locked' => $operationallyLocked,
            'is_payroll_approved' => $timesheet->isPayrollApproved(),
            'preparation_status' => $timesheet->preparation?->status?->value,
            'preparation_version' => $timesheet->preparation?->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toBoardRow(
        Employee $employee,
        ?CrewTimesheet $timesheet,
        int $periodId,
        CarbonInterface $asOf,
    ): array {
        $paymentMethod = $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer;
        $contract = $employee->currentContract;
        $salaryStructure = $contract?->resolvedSalaryStructure() ?? ContractSalaryStructure::Daily;

        return [
            'employee' => PayrollEmployeeIdentityResource::forEmployee($employee),
            'period_id' => $periodId,
            'timesheet' => self::toArray($timesheet),
            'is_filled' => $timesheet !== null,
            'operational_source' => self::operationalSource($timesheet, $salaryStructure),
            'operational_source_label' => self::operationalSourceLabel($timesheet, $salaryStructure),
            'approval_status' => self::boardApprovalStatus($timesheet, $salaryStructure),
            'approval_status_label' => self::boardApprovalStatusLabel($timesheet, $salaryStructure),
            'primary_account' => EmployeePrimaryAccountResource::forEmployee($employee),
            'salary_payment_method' => $paymentMethod->value,
            'salary_payment_method_label' => $paymentMethod->label(),
            'salary_structure' => $salaryStructure->value,
            'contract' => $contract !== null
                ? app(ResolveContractRatesForPeriod::class)->handle($contract, $asOf)
                : null,
        ];
    }

    public static function approvalStatusLabel(CrewTimesheet $timesheet): string
    {
        if ($timesheet->isOperationallyLocked()) {
            return 'Applied/Approved';
        }

        return ($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)->label();
    }

    /**
     * @return 'applied'|'draft'|'submitted'|'approved'|'returned'|'not_applicable'|'not_entered'
     */
    public static function boardApprovalStatus(
        ?CrewTimesheet $timesheet,
        ContractSalaryStructure $salaryStructure,
    ): string {
        if ($salaryStructure === ContractSalaryStructure::Monthly && $timesheet === null) {
            return 'not_applicable';
        }

        if ($timesheet === null) {
            return 'not_entered';
        }

        if ($timesheet->source === CrewTimesheetSource::CrewOperations && $timesheet->isOperationallyLocked()) {
            return 'applied';
        }

        return ($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)->value;
    }

    public static function boardApprovalStatusLabel(
        ?CrewTimesheet $timesheet,
        ContractSalaryStructure $salaryStructure,
    ): string {
        return match (self::boardApprovalStatus($timesheet, $salaryStructure)) {
            'applied' => 'Applied/Approved',
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'returned' => 'Returned',
            'not_applicable' => 'Not applicable',
            'not_entered' => 'Not Entered',
        };
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
            'crew_operations' => 'Crew Operations',
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
