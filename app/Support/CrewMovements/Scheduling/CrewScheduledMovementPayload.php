<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewMovementAction;

/**
 * Sanitizes validated schedule payloads for persistence and later execution.
 * Does not store operator company context or secrets.
 */
final class CrewScheduledMovementPayload
{
    /**
     * Keys that belong to schedule metadata rather than movement execution.
     *
     * @var list<string>
     */
    private const META_KEYS = [
        'action',
        'mode',
        'scheduled_at',
        'scheduled_timezone',
        'action_fields',
        'check_out_date_auto_synced',
        'source_check_out_date_auto_synced',
    ];

    /**
     * Known movement field keys that may appear in action_fields / action_payload.
     *
     * @var list<string>
     */
    private const ALLOWED_ACTION_FIELD_KEYS = [
        'vessel_id',
        'position_id',
        'client_id',
        'next_phase',
        'starting_phase',
        'accommodation_status',
        'hotel_id',
        'room_type_id',
        'check_in_date',
        'check_out_date',
        'source_check_out_date',
        'no_hotel_accommodation',
        'planned_signoff_choice',
        'planned_signoff_at',
        'planned_signoff_override_reason',
        'provider',
        'course',
        'course_id',
        'completion_intent',
        'remarks',
        'check_out_date_auto_synced',
        'source_check_out_date_auto_synced',
        'planned_start_at',
        'planned_end_at',
        'sync_training_to_employee_training',
        'planned_arrival_at',
    ];

    /**
     * @return list<string>
     */
    public static function allowedActionFieldKeys(): array
    {
        return self::ALLOWED_ACTION_FIELD_KEYS;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    public static function unknownActionFieldKeys(array $fields): array
    {
        return array_values(array_diff(array_keys($fields), self::ALLOWED_ACTION_FIELD_KEYS));
    }

    /**
     * Keep only allowlisted movement field keys (drops meta / unknown / nested junk).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function onlyAllowedActionFields(array $fields): array
    {
        return array_intersect_key($fields, array_flip(self::ALLOWED_ACTION_FIELD_KEYS));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function fromValidated(CrewMovementAction $action, array $validated): array
    {
        $payload = collect($validated)
            ->except(self::META_KEYS)
            ->all();

        // Scheduled movements apply occurred_at only at execution time.
        unset($payload['occurred_at']);

        $payload = self::onlyAllowedActionFields($payload);

        if (array_key_exists('check_out_date_auto_synced', $validated)) {
            $payload['check_out_date_auto_synced'] = (bool) $validated['check_out_date_auto_synced'];
        }

        if (array_key_exists('source_check_out_date_auto_synced', $validated)) {
            $payload['source_check_out_date_auto_synced'] = (bool) $validated['source_check_out_date_auto_synced'];
        }

        $payload['_action'] = $action->value;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function forExecution(array $payload, string $occurredAtLocal): array
    {
        $execution = $payload;
        unset(
            $execution['_action'],
            $execution['check_out_date_auto_synced'],
            $execution['source_check_out_date_auto_synced'],
        );
        $execution['occurred_at'] = $occurredAtLocal;

        return $execution;
    }

    /**
     * When rescheduling, keep manually overridden checkout dates but follow
     * the new scheduled date when the checkout was auto-synced.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function syncAutoLinkedDates(array $payload, string $scheduledAtLocal): array
    {
        $date = substr($scheduledAtLocal, 0, 10);

        if (($payload['check_out_date_auto_synced'] ?? false) === true) {
            $payload['check_out_date'] = $date;
        }

        if (($payload['source_check_out_date_auto_synced'] ?? false) === true) {
            $payload['source_check_out_date'] = $date;
        }

        return $payload;
    }
}
