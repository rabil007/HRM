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

        $scheduledAt = $this->input('scheduled_at');

        // Apply auto-linked hotel checkout synchronization before validating the
        // final payload so quick date-only reschedules (and full edits) keep
        // check-out chronology aligned with the new scheduled date.
        if (is_string($scheduledAt) && $scheduledAt !== '') {
            $merged = CrewScheduledMovementPayload::syncAutoLinkedDates($merged, $scheduledAt);
        }

        $this->merge(array_merge($merged, [
            'action' => $schedule->movement_action->value,
            'mode' => 'schedule_later',
            'scheduled_at' => $scheduledAt,
            'occurred_at' => $scheduledAt,
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
        $existing = CrewScheduledMovementPayload::onlyAllowedActionFields($existing);

        // Prefer normalized validated values over raw nested action_fields input.
        // prepareForValidation already merged existing + incoming + auto-synced dates.
        $validatedFields = CrewScheduledMovementPayload::onlyAllowedActionFields($validated);

        // Always prefer the validated (post-sync) field values so quick reschedule
        // and full edit both persist the final normalized payload.
        $actionFields = array_merge($existing, $validatedFields);
        $scheduledAt = (string) ($validated['scheduled_at'] ?? '');
        $actionFields = CrewScheduledMovementPayload::syncAutoLinkedDates($actionFields, $scheduledAt);

        return [
            'scheduled_at' => $scheduledAt,
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
