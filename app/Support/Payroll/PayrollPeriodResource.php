<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;

final class PayrollPeriodResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'payment_date' => $period->payment_date?->toDateString(),
            'payroll_category' => $period->payroll_category?->value ?? PayrollCategory::Crew->value,
            'payroll_category_label' => $period->payroll_category?->label() ?? PayrollCategory::Crew->label(),
            'supports_timesheets' => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew,
            'status' => $period->status?->value,
            'status_label' => $period->status?->label(),
            'notes' => $period->notes,
            'excluded_employee_ids' => array_values(array_map(
                intval(...),
                $period->excluded_employee_ids ?? [],
            )),
            'is_editable' => $period->isEditable(),
            'can_generate_crew_payroll' => $period->canGenerateCrewPayroll(),
            'can_generate_payroll' => $period->canGeneratePayroll(),
            'can_revert_to_draft' => $period->canRevertToDraft(),
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
            'has_payment_proof' => filled($period->payment_proof_path),
            'payment_proof_url' => filled($period->payment_proof_path) ? route('payroll.payment-proof', $period) : null,
            'created_at' => $period->created_at?->toDateTimeString(),
        ];
    }
}
