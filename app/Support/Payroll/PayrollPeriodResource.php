<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodCreationSource;
use App\Models\PayrollPeriod;

final class PayrollPeriodResource
{
    /**
     * @param  array<string, mixed>|null  $generationSummary
     */
    public static function toArray(PayrollPeriod $period, ?array $generationSummary = null): array
    {
        $paths = $period->payment_proof_paths ?? [];
        if (empty($paths) && filled($period->payment_proof_path)) {
            $paths = [$period->payment_proof_path];
        }

        $proofs = [];
        foreach ($paths as $index => $path) {
            $proofs[] = [
                'id' => $index,
                'name' => basename($path),
                'url' => route('payroll.payment-proof', ['payrollPeriod' => $period, 'index' => $index]),
            ];
        }

        if ($generationSummary === null && $period->isCrew()) {
            $generationSummary = app(BuildCrewPayrollCoverageSummary::class)->handle(
                $period,
                (int) $period->company_id,
            );
        }

        return [
            'id' => $period->id,
            'name' => $period->name,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'payment_date' => $period->payment_date?->toDateString(),
            'generated_at' => $period->generated_at?->toDateTimeString(),
            'payroll_category' => $period->payroll_category?->value ?? PayrollCategory::Crew->value,
            'payroll_category_label' => $period->payroll_category?->label() ?? PayrollCategory::Crew->label(),
            'crew_timesheet_mode' => $period->crew_timesheet_mode?->value,
            'crew_timesheet_mode_label' => $period->crewTimesheetModeLabel(),
            'uses_crew_operations_timesheets' => $period->usesCrewOperationsTimesheets(),
            'uses_mixed_timesheet_sources' => $period->usesMixedTimesheetSources(),
            'requires_exclusive_crew_operations_timesheets' => $period->requiresExclusiveCrewOperationsTimesheets(),
            'uses_manual_timesheets' => $period->usesManualTimesheets(),
            'allows_fallback_operational_entry' => $period->allowsFallbackOperationalEntry(),
            'supports_timesheets' => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew,
            'status' => $period->status?->value,
            'status_label' => $period->status?->label(),
            'creation_source' => $period->creation_source?->value ?? PayrollPeriodCreationSource::Manual->value,
            'creation_source_label' => ($period->creation_source ?? PayrollPeriodCreationSource::Manual)->label(),
            'is_automatic' => $period->isAutomatic(),
            'notes' => $period->notes,
            'excluded_employee_ids' => array_values(array_map(
                intval(...),
                $period->excluded_employee_ids ?? [],
            )),
            'is_editable' => $period->isEditable(),
            'can_generate_crew_payroll' => $period->canGenerateCrewPayroll(),
            'can_generate_payroll' => $period->canGeneratePayroll(),
            'generation_ready' => $generationSummary['ready'] ?? true,
            'generation_can_confirm' => $generationSummary['can_generate'] ?? true,
            'generation_blocking_reason' => $generationSummary['period_blocking_reason']
                ?? (($generationSummary['blocking_count'] ?? 0) > 0
                    ? ($generationSummary['blocking_reason'] ?? null)
                    : null),
            'generation_preview' => $generationSummary,
            'can_revert_to_draft' => $period->canRevertToDraft(),
            'can_revert_to_approved' => $period->canRevertToApproved(),
            'can_revert_to_processing' => $period->canRevertToProcessing(),
            'can_approve' => $period->canApprove(),
            'can_mark_paid' => $period->canMarkPaid(),
            'can_cancel' => $period->canCancel(),
            'payroll_records_count' => (int) ($period->payroll_records_count ?? 0),
            'approved_at' => $period->approved_at?->toDateTimeString(),
            'approver' => $period->relationLoaded('approvedBy') && $period->approvedBy !== null
                ? [
                    'id' => $period->approvedBy->id,
                    'name' => $period->approvedBy->name,
                ]
                : null,
            'has_payment_proof' => ! empty($proofs),
            'payment_proof_url' => $proofs[0]['url'] ?? null,
            'payment_proofs' => $proofs,
            'created_at' => $period->created_at?->toDateTimeString(),
        ];
    }
}
