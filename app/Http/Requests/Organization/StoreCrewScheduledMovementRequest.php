<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewMovementAction;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\Scheduling\CrewSchedulableMovementActions;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementAccess;
use Illuminate\Validation\Rule;

class StoreCrewScheduledMovementRequest extends PerformCrewMovementActionRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null || ! CrewScheduledMovementAccess::canSchedule($this->user())) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        /** @var CrewAssignment|null $assignment */
        $assignment = $this->route('assignment');

        if (! $assignment instanceof CrewAssignment) {
            return false;
        }

        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $this->user());

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mode' => 'schedule_later',
        ]);

        if ($this->filled('scheduled_at') && ! $this->filled('occurred_at')) {
            // Reuse Record Now chronology/accommodation rules against the intended instant.
            $this->merge(['occurred_at' => $this->input('scheduled_at')]);
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        $rules = parent::rules();

        $rules['mode'] = ['required', 'string', Rule::in(['schedule_later'])];
        $rules['scheduled_at'] = ['required', 'date'];
        $rules['action'] = [
            'required',
            'string',
            Rule::in(CrewSchedulableMovementActions::values()),
        ];
        $rules['check_out_date_auto_synced'] = ['sometimes', 'boolean'];
        $rules['source_check_out_date_auto_synced'] = ['sometimes', 'boolean'];
        $rules['remarks'] = ['nullable', 'string', 'max:1000'];

        // Scheduled movements never require an actual occurred_at on submit.
        $rules['occurred_at'] = ['nullable', 'date'];

        return $rules;
    }

    protected function isSchedulingMode(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulePayload(): array
    {
        $validated = $this->validated();
        $validated['scheduled_at'] = (string) ($validated['scheduled_at'] ?? $validated['occurred_at'] ?? '');

        return $validated;
    }

    public function movementAction(): CrewMovementAction
    {
        return CrewMovementAction::from((string) $this->validated('action'));
    }
}
