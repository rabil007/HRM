<?php

namespace App\Support\Payroll;

use App\Models\SalaryAdjustment;

final class SalaryAdjustmentResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SalaryAdjustment $adjustment): array
    {
        $adjustment->loadMissing(['employee', 'period', 'approver']);

        return [
            'id' => $adjustment->id,
            'employee' => [
                'id' => $adjustment->employee_id,
                'name' => $adjustment->employee?->name ?? '—',
                'employee_no' => $adjustment->employee?->employee_no,
            ],
            'period' => $adjustment->period_id !== null ? [
                'id' => $adjustment->period_id,
                'name' => $adjustment->period?->name ?? '—',
            ] : null,
            'type' => $adjustment->type?->value,
            'type_label' => $adjustment->type?->label(),
            'amount' => number_format((float) $adjustment->amount, 2, '.', ''),
            'reason' => $adjustment->reason,
            'rejection_reason' => $adjustment->rejection_reason,
            'status' => $adjustment->status?->value,
            'status_label' => $adjustment->status?->label(),
            'approved_at' => $adjustment->approved_at?->toDateTimeString(),
            'approver' => $adjustment->approver !== null ? [
                'id' => $adjustment->approver->id,
                'name' => $adjustment->approver->name,
            ] : null,
            'created_at' => $adjustment->created_at?->toDateTimeString(),
        ];
    }
}
