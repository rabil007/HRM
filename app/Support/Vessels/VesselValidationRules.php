<?php

namespace App\Support\Vessels;

use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class VesselValidationRules
{
    /**
     * Common validation rules for creating a Vessel.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function storeRules(int $companyId): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vessels', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->whereNull('deleted_at'),
            ],
            'client_id' => ClientAssignmentRules::activeClientIdRules(required: true),
            'vessel_type_id' => ['required', 'integer', Rule::exists('vessel_types', 'id')],
            'grt' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'bhp' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'official_no' => ['nullable', 'string', 'max:100'],
            'call_sign' => ['nullable', 'string', 'max:100'],
            'imo_no' => ['nullable', 'string', 'max:100'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
