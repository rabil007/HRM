<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\Employees\HistoricalCompanyEmployeeRule;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewHistoricalCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('createHistorical', CrewAssignment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $accommodationChoices = HistoricalCrewAssignmentData::accommodationChoices();

        return [
            'employee_id' => [
                'required',
                'integer',
                HistoricalCompanyEmployeeRule::exists($companyId, $this->user()),
            ],
            'vessel_id' => [
                'required',
                'integer',
                Rule::exists('vessels', 'id')->where('company_id', $companyId),
            ],
            'rank_id' => ['required', 'integer', Rule::exists('ranks', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'sign_on_standby_from' => ['nullable', 'date'],
            'sign_on_standby_to' => ['nullable', 'date'],
            'onsite_from' => ['nullable', 'date'],
            'onsite_to' => ['nullable', 'date'],
            'sign_off_standby_from' => ['nullable', 'date'],
            'sign_off_standby_to' => ['nullable', 'date'],
            'home_available_from' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'sign_on_accommodation' => ['nullable', 'string', Rule::in($accommodationChoices)],
            'sign_on_hotel_id' => [
                'nullable',
                'integer',
                Rule::exists('hotels', 'id')->where('company_id', $companyId),
            ],
            'sign_on_room_type_id' => [
                'nullable',
                'integer',
                Rule::exists('room_types', 'id')->where('company_id', $companyId),
            ],
            'sign_on_hotel_check_in' => ['nullable', 'date'],
            'sign_on_hotel_check_out' => ['nullable', 'date'],
            'sign_off_accommodation' => ['nullable', 'string', Rule::in($accommodationChoices)],
            'sign_off_hotel_id' => [
                'nullable',
                'integer',
                Rule::exists('hotels', 'id')->where('company_id', $companyId),
            ],
            'sign_off_room_type_id' => [
                'nullable',
                'integer',
                Rule::exists('room_types', 'id')->where('company_id', $companyId),
            ],
            'sign_off_hotel_check_in' => ['nullable', 'date'],
            'sign_off_hotel_check_out' => ['nullable', 'date'],
            // Legacy detailed movement events are no longer accepted.
            'mobilisation_at' => ['prohibited'],
            'mobilisation_start_at' => ['prohibited'],
            'join_standby_at' => ['prohibited'],
            'training_start_at' => ['prohibited'],
            'training_started_at' => ['prohibited'],
            'training_end_at' => ['prohibited'],
            'training_ended_at' => ['prohibited'],
            'joined_vessel_at' => ['prohibited'],
            'disembarked_at' => ['prohibited'],
            'travel_home_at' => ['prohibited'],
            'arrival_at' => ['prohibited'],
            'ready_to_join_at' => ['prohibited'],
            'post_training_join_standby_at' => ['prohibited'],
            'demob_standby_at' => ['prohibited'],
            'post_signoff_standby_at' => ['prohibited'],
            'assignment_closed_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $movementFields = [
                'sign_on_standby_from',
                'onsite_from',
                'sign_off_standby_from',
                'home_available_from',
            ];

            $hasMovement = false;

            foreach ($movementFields as $field) {
                $value = $this->input($field);

                if (is_string($value) && trim($value) !== '') {
                    $hasMovement = true;
                    break;
                }
            }

            if (! $hasMovement) {
                $validator->errors()->add(
                    'dates',
                    'At least one meaningful movement period must be supplied.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sign_on_accommodation' => HistoricalCrewAssignmentData::canonicalizeAccommodationInput(
                $this->input('sign_on_accommodation'),
            ),
            'sign_off_accommodation' => HistoricalCrewAssignmentData::canonicalizeAccommodationInput(
                $this->input('sign_off_accommodation'),
            ),
        ]);
    }

    public function toData(): HistoricalCrewAssignmentData
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $timezone = CompanyTimezone::forCompanyId($companyId);

        return HistoricalCrewAssignmentData::fromArray(
            data: $this->validated(),
            companyId: $companyId,
            timezone: $timezone,
        );
    }
}
