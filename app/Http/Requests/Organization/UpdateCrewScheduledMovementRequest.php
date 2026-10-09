<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewMovementAction;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementAccess;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementPayload;
use Illuminate\Validation\Validator;

/**
 * Reschedule / edit uses the same authoritative action-field rules as create.
 * Nested action_fields are merged over the existing payload; unknown keys are rejected.
 */
class UpdateCrewScheduledMovementRequest extends StoreCrewScheduledMovementRequest
{
    private bool $rejectUnknownActionFields = false;

    /**
     * @var list<string>
     */
    private array $unknownActionFieldKeys = [];

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

    protected function prepareForValidation(): void
    {
        /** @var CrewScheduledMovement $schedule */
        $schedule = $this->route('scheduledMovement');

        $assignment = CrewAssignment::query()
            ->where('company_id', (int) $schedule->company_id)
            ->whereKey($schedule->crew_assignment_id)
            ->first();

        if ($assignment !== null) {
            $this->route()->setParameter('assignment', $assignment);
        }

        $existing = is_array($schedule->action_payload) ? $schedule->action_payload : [];
        unset($existing['_action']);

        $incoming = $this->input('action_fields');
        $merged = $existing;

        if ($incoming !== null) {
            if (! is_array($incoming)) {
                $this->merge(['action_fields' => []]);
            } else {
                $unknown = CrewScheduledMovementPayload::unknownActionFieldKeys($incoming);
                if ($unknown !== []) {
                    $this->rejectUnknownActionFields = true;
                    $this->unknownActionFieldKeys = $unknown;
                }

                $merged = array_merge($existing, $incoming);
            }
        }

        $this->merge(array_merge($merged, [
            'action' => $schedule->movement_action->value,
            'mode' => 'schedule_later',
            'scheduled_at' => $this->input('scheduled_at'),
            'occurred_at' => $this->input('scheduled_at'),
        ]));

        parent::prepareForValidation();
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($this->rejectUnknownActionFields) {
                $validator->errors()->add(
                    'action_fields',
                    'Unknown or unsupported action fields: '.implode(', ', $this->unknownActionFieldKeys).'.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePayload(): array
    {
        $validated = $this->validated();
        $schedule = $this->route('scheduledMovement');
        assert($schedule instanceof CrewScheduledMovement);

        $existing = is_array($schedule->action_payload) ? $schedule->action_payload : [];
        unset($existing['_action']);

        $actionFields = array_key_exists('action_fields', $this->all())
            ? array_merge($existing, is_array($this->input('action_fields')) ? $this->input('action_fields') : [])
            : $existing;

        // Strip meta / unknown before persistence helper runs.
        foreach (array_keys($actionFields) as $key) {
            if ($key === '_action') {
                unset($actionFields[$key]);
            }
        }

        return [
            'scheduled_at' => (string) ($validated['scheduled_at'] ?? ''),
            'action_fields' => $actionFields,
            'check_out_date_auto_synced' => $validated['check_out_date_auto_synced'] ?? ($actionFields['check_out_date_auto_synced'] ?? null),
            'source_check_out_date_auto_synced' => $validated['source_check_out_date_auto_synced'] ?? ($actionFields['source_check_out_date_auto_synced'] ?? null),
            'remarks' => $validated['remarks'] ?? ($actionFields['remarks'] ?? null),
        ];
    }

    public function movementAction(): CrewMovementAction
    {
        /** @var CrewScheduledMovement $schedule */
        $schedule = $this->route('scheduledMovement');

        return $schedule->movement_action;
    }
}
