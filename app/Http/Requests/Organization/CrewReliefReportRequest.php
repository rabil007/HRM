<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Support\CrewMovements\CrewReliefFocusMatcher;
use App\Support\Reports\CrewRelief\CrewReliefReportFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrewReliefReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'vessel_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],

            'planned_signoff_from' => ['nullable', 'date'],
            'planned_signoff_to' => [
                'nullable',
                'date',
                'after_or_equal:planned_signoff_from',
            ],
            'preset' => [
                'nullable',
                'string',
                Rule::in([
                    CrewReliefReportFilters::PRESET_NEXT_7_DAYS,
                    CrewReliefReportFilters::PRESET_NEXT_14_DAYS,
                    CrewReliefReportFilters::PRESET_NEXT_30_DAYS,
                    CrewReliefReportFilters::PRESET_NO_RELIEF,
                    CrewReliefReportFilters::PRESET_NOT_READY,
                    CrewReliefReportFilters::PRESET_OVERDUE,
                    CrewReliefReportFilters::PRESET_MISSING_PLANNED_SIGNOFF,
                    CrewReliefReportFilters::PRESET_ALL,
                ]),
            ],
            'relief_status' => [
                'nullable',
                'string',
                Rule::enum(CrewReliefStatus::class),
            ],
            'relief_risk' => [
                'nullable',
                'string',
                Rule::enum(CrewReliefRisk::class),
            ],
            'focus' => [
                'nullable',
                'string',
                Rule::in(CrewReliefFocusMatcher::focusValues()),
            ],
            'readiness' => [
                'nullable',
                'string',
                Rule::in(['all', 'ready', 'in_progress', 'not_assigned', 'at_risk', 'joined']),
            ],
            'attention' => [
                'nullable',
                'string',
                Rule::in(['all', 'critical', 'warning', 'healthy']),
            ],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'string', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
