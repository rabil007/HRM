<?php

namespace App\Http\Requests\Organization\VesselManning;

use App\Models\Vessel;
use App\Models\VesselManning;
use App\Support\Positions\CrewPositionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVesselManningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        /** @var Vessel|null $vessel */
        $vessel = $this->route('vessel');

        if (! $vessel instanceof Vessel) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        if ((int) $vessel->company_id !== $companyId) {
            return false;
        }

        $requirements = $this->input('requirements', []);

        if (! is_array($requirements) || $requirements === []) {
            return $user->can('crew_operations.vessel_manning.delete');
        }

        $hasExisting = VesselManning::query()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vessel->id)
            ->exists();

        if (! $hasExisting) {
            return $user->can('crew_operations.vessel_manning.create');
        }

        return $user->can('crew_operations.vessel_manning.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'requirements' => ['present', 'array'],
            'redirect_to' => ['nullable', 'string', 'in:show'],
            'requirements.*.position_id' => [
                'required',
                'integer',
                'distinct',
                CrewPositionCatalog::existsCrewPositionRule($companyId),
            ],
            'requirements.*.required_count' => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Vessel|null $vessel */
            $vessel = $this->route('vessel');

            if (! $vessel instanceof Vessel) {
                return;
            }

            if (! $vessel->is_active) {
                $validator->errors()->add('vessel', 'Manning cannot be updated for an inactive vessel.');
            }
        });
    }
}
