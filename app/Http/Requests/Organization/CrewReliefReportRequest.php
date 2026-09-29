<?php

namespace App\Http\Requests\Organization;

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
            'rank_id' => ['nullable', 'integer'],
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
                    CrewReliefReportFilters::PRESET_ALL,
                ]),
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
