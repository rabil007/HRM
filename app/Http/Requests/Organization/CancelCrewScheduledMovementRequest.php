<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementAccess;
use Illuminate\Foundation\Http\FormRequest;

class CancelCrewScheduledMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null || ! CrewScheduledMovementAccess::canManage($this->user())) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        /** @var CrewScheduledMovement|null $schedule */
        $schedule = $this->route('scheduledMovement');

        if (! $schedule instanceof CrewScheduledMovement) {
            return false;
        }

        CrewScheduledMovementAccess::assertInCompany($schedule, $companyId, $this->user());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
