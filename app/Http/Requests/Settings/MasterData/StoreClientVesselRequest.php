<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Models\Client;
use App\Support\Vessels\VesselValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreClientVesselRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crew_operations.vessels.create');
    }

    protected function prepareForValidation(): void
    {
        $routeClient = $this->route('client');
        $routeClientId = $routeClient instanceof Client ? (int) $routeClient->id : (int) $routeClient;

        if ($routeClient instanceof Client && ! $routeClient->is_active) {
            throw ValidationException::withMessages([
                'client_id' => 'Activate this client before adding new projects or vessels.',
                'name' => 'Activate this client before adding new projects or vessels.',
            ]);
        }

        if ($this->has('client_id') && $this->input('client_id') !== null && $this->input('client_id') !== '' && (int) $this->input('client_id') !== $routeClientId) {
            throw ValidationException::withMessages([
                'client_id' => 'Contextual client tampering detected.',
            ]);
        }

        $this->merge([
            'client_id' => $routeClientId,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return VesselValidationRules::storeRules($companyId);
    }
}
