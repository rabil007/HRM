<?php

namespace App\Http\Requests\Organization\Vessel;

use App\Models\Vessel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVesselRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crew_operations.vessels.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        /** @var Vessel|null $vessel */
        $vessel = $this->route('vessel');
        $vesselId = (int) ($vessel?->id ?? 0);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vessels', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->whereNull('deleted_at')
                    ->ignore($vesselId),
            ],
            'vessel_type_id' => ['required', 'integer', Rule::exists('vessel_types', 'id')],
            'grt' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'bhp' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'official_no' => ['nullable', 'string', 'max:100'],
            'call_sign' => ['nullable', 'string', 'max:100'],
            'imo_no' => ['nullable', 'string', 'max:100'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
            'redirect_to' => ['nullable', 'string', 'in:show'],
        ];
    }
}
