<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $vesselId = $this->input('vessel_id');
        $clientId = $this->input('client_id');

        if (($clientId === null || $clientId === '')
            && $vesselId !== null
            && $vesselId !== ''
            && $companyId > 0) {
            $resolved = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vesselId);

            if ($resolved !== null) {
                $this->merge(['client_id' => $resolved]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $existingVesselId = $this->existingAssignment()?->vessel_id;

        return [
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vessel_id' => [
                'nullable',
                'integer',
                Rule::exists('vessels', 'id')->where(function ($query) use ($companyId, $existingVesselId): void {
                    $query->where('company_id', $companyId)
                        ->where(function ($inner) use ($existingVesselId): void {
                            $inner->where('is_active', true);

                            if ($existingVesselId !== null) {
                                $inner->orWhere('id', (int) $existingVesselId);
                            }
                        });
                }),
            ],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'planned_join_at' => ['nullable', 'date'],
            'planned_signoff_at' => ['nullable', 'date', 'after_or_equal:planned_join_at'],
            'planned_travel_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $assignment = $this->existingAssignment();
            $companyId = (int) $this->attributes->get('current_company_id');
            $clientId = $this->nullableInt($this->input('client_id'));
            $vesselId = $this->nullableInt($this->input('vessel_id'));

            $existingClientId = $assignment?->client_id !== null ? (int) $assignment->client_id : null;
            $existingVesselId = $assignment?->vessel_id !== null ? (int) $assignment->vessel_id : null;

            // Preserve an unchanged legacy Vessel/Client pair on editable records.
            // New operational Vessel/Client selections remain strictly validated.
            if ($clientId === $existingClientId && $vesselId === $existingVesselId) {
                return;
            }

            ClientAssignmentRules::vesselBelongsToClient(
                $validator,
                $companyId,
                $clientId,
                $vesselId,
            );
        });
    }

    private function existingAssignment(): ?CrewAssignment
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof CrewAssignment ? $assignment : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
