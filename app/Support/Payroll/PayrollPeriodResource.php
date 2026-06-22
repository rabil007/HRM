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
            'is_editable' => $period->isEditable(),
            'created_at' => $period->created_at?->toDateTimeString(),
        ];
    }
}
