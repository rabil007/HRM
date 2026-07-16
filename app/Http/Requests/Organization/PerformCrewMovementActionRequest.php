<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewMovementAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PerformCrewMovementActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $action = $this->input('action');

        $baseRules = [
            'action' => ['required', 'string', Rule::in(CrewMovementAction::values())],
            'occurred_at' => [Rule::requiredIf(fn () => in_array($action, [
                'approve_mobilisation',
                'record_arrival',
                'start_join_standby',
                'send_to_training',
                'complete_training',
                'mark_ready',
                'join_vessel',
                'confirm_disembarkation',
                'start_demob_standby',
                'travel_home',
                'close_assignment',
            ], true)), 'nullable', 'date'],
            'next_phase' => [Rule::requiredIf(fn () => in_array($action, [
                'record_arrival',
                'complete_training',
                'confirm_disembarkation',
            ], true)), 'nullable', 'string'],
        ];

        if ($action === 'join_vessel') {
            $baseRules['vessel_id'] = ['required', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)];
            $baseRules['rank_id'] = ['required', 'integer', Rule::exists('ranks', 'id')->where('company_id', $companyId)];
            $baseRules['client_id'] = ['nullable', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)];
            $baseRules['company_visa_type_id'] = ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('company_id', $companyId)];
            $baseRules['planned_signoff_at'] = ['nullable', 'date'];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'send_to_training') {
            $baseRules['provider'] = ['nullable', 'string', 'max:200'];
            $baseRules['course'] = ['nullable', 'string', 'max:200'];
            $baseRules['planned_start_at'] = ['nullable', 'date'];
            $baseRules['planned_end_at'] = ['nullable', 'date'];
            $baseRules['remarks'] = ['nullable', 'string', 'max:1000'];
        }

        if ($action === 'plan_signoff') {
            $baseRules['planned_signoff_at'] = ['required', 'date'];
        }

        if ($action === 'travel_home') {
            $baseRules['planned_travel_at'] = ['nullable', 'date'];
        }

        if ($action === 'cancel_assignment') {
            $baseRules['reason'] = ['required', 'string', 'max:500'];
        }

        return $baseRules;
    }
}
