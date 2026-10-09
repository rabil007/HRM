import type {
    CrewMovementAction,
    CrewMovementActionFormData,
    CrewScheduledMovementCard,
} from '../types';

/**
 * Mirrors `CrewScheduledMovementPayload::ALLOWED_ACTION_FIELD_KEYS`.
 * Edit / Reschedule must only submit keys from this set.
 */
export const ALLOWED_SCHEDULE_ACTION_FIELD_KEYS = [
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
] as const;

export type AllowedScheduleActionFieldKey =
    (typeof ALLOWED_SCHEDULE_ACTION_FIELD_KEYS)[number];

const ALLOWED_SET = new Set<string>(ALLOWED_SCHEDULE_ACTION_FIELD_KEYS);

/**
 * Action-scoped keys for Edit / Reschedule `action_fields`.
 * Keep in sync with PerformCrewMovementActionRequest rules for each action.
 */
export const ACTION_SCHEDULE_FIELD_KEYS: Partial<
    Record<CrewMovementAction, readonly AllowedScheduleActionFieldKey[]>
> = {
    approve_mobilisation: ['remarks'],
    record_arrival: [
        'next_phase',
        'accommodation_status',
        'hotel_id',
        'room_type_id',
        'check_in_date',
        'remarks',
    ],
    send_to_training: [
        'provider',
        'course',
        'course_id',
        'planned_start_at',
        'planned_end_at',
        'remarks',
    ],
    complete_training: [
        'next_phase',
        'provider',
        'course',
        'course_id',
        'sync_training_to_employee_training',
        'remarks',
    ],
    join_vessel: [
        'vessel_id',
        'position_id',
        'client_id',
        'planned_signoff_choice',
        'planned_signoff_at',
        'planned_signoff_override_reason',
        'check_out_date',
        'remarks',
        'check_out_date_auto_synced',
    ],
    confirm_disembarkation: [
        'next_phase',
        'accommodation_status',
        'hotel_id',
        'room_type_id',
        'check_in_date',
        'remarks',
    ],
    travel_home: [
        'check_out_date',
        'completion_intent',
        'remarks',
        'check_out_date_auto_synced',
    ],
    transfer_vessel: [
        'vessel_id',
        'position_id',
        'client_id',
        'planned_signoff_choice',
        'planned_signoff_at',
        'planned_signoff_override_reason',
        'remarks',
    ],
    redeploy: [
        'starting_phase',
        'planned_arrival_at',
        'vessel_id',
        'position_id',
        'client_id',
        'planned_signoff_choice',
        'planned_signoff_at',
        'planned_signoff_override_reason',
        'source_check_out_date',
        'accommodation_status',
        'hotel_id',
        'room_type_id',
        'check_in_date',
        'remarks',
        'source_check_out_date_auto_synced',
    ],
    close_assignment: ['remarks'],
};

export function isAllowedScheduleActionFieldKey(
    key: string,
): key is AllowedScheduleActionFieldKey {
    return ALLOWED_SET.has(key);
}

/**
 * Build the nested `action_fields` object for Edit / Reschedule PUT.
 * Only includes keys relevant to the movement action and permitted by the backend allowlist.
 */
export function buildScheduledMovementEditActionFields(
    action: CrewMovementAction,
    payload: Partial<CrewMovementActionFormData> & Record<string, unknown>,
    options: {
        checkOutDateAutoSynced?: boolean;
        sourceCheckOutDateAutoSynced?: boolean;
    } = {},
): Record<string, unknown> {
    const keys = ACTION_SCHEDULE_FIELD_KEYS[action] ?? [];
    const fields: Record<string, unknown> = {};

    for (const key of keys) {
        if (!isAllowedScheduleActionFieldKey(key)) {
            continue;
        }

        if (key === 'check_out_date_auto_synced') {
            if (options.checkOutDateAutoSynced !== undefined) {
                fields[key] = Boolean(options.checkOutDateAutoSynced);
            }

            continue;
        }

        if (key === 'source_check_out_date_auto_synced') {
            if (options.sourceCheckOutDateAutoSynced !== undefined) {
                fields[key] = Boolean(options.sourceCheckOutDateAutoSynced);
            }

            continue;
        }

        if (!Object.prototype.hasOwnProperty.call(payload, key)) {
            continue;
        }

        const value = payload[key];

        if (value === undefined) {
            continue;
        }

        fields[key] = value;
    }

    return fields;
}

/**
 * Prefill the movement dialog form from an existing scheduled movement card.
 */
export function applyScheduledMovementFormPrefill(
    data: CrewMovementActionFormData,
    schedule: CrewScheduledMovementCard | null | undefined,
): CrewMovementActionFormData {
    if (!schedule) {
        return data;
    }

    const payload = schedule.action_payload ?? {};
    const scheduledAt =
        schedule.scheduled_at_input?.replace('T', ' ') ??
        schedule.scheduled_at ??
        data.occurred_at;

    return {
        ...data,
        ...Object.fromEntries(
            Object.entries(payload).filter(
                ([key]) => key !== '_action' && key in data,
            ),
        ),
        occurred_at: scheduledAt.slice(0, 16).replace('T', ' '),
        action: schedule.movement_action as CrewMovementAction,
    };
}

export function buildScheduledMovementEditPayload(
    action: CrewMovementAction,
    payload: Partial<CrewMovementActionFormData> & Record<string, unknown>,
    options: {
        checkOutDateAutoSynced?: boolean;
        sourceCheckOutDateAutoSynced?: boolean;
    } = {},
): {
    scheduled_at: string;
    action_fields: Record<string, unknown>;
    check_out_date_auto_synced?: boolean;
    source_check_out_date_auto_synced?: boolean;
} {
    const keys = ACTION_SCHEDULE_FIELD_KEYS[action] ?? [];
    const scopedOptions = {
        checkOutDateAutoSynced: keys.includes('check_out_date_auto_synced')
            ? options.checkOutDateAutoSynced
            : undefined,
        sourceCheckOutDateAutoSynced: keys.includes(
            'source_check_out_date_auto_synced',
        )
            ? options.sourceCheckOutDateAutoSynced
            : undefined,
    };

    const scheduledAt = String(
        payload.occurred_at ?? payload.scheduled_at ?? '',
    );
    const actionFields = buildScheduledMovementEditActionFields(
        action,
        payload,
        scopedOptions,
    );

    const result: {
        scheduled_at: string;
        action_fields: Record<string, unknown>;
        check_out_date_auto_synced?: boolean;
        source_check_out_date_auto_synced?: boolean;
    } = {
        scheduled_at: scheduledAt,
        action_fields: actionFields,
    };

    if (scopedOptions.checkOutDateAutoSynced !== undefined) {
        result.check_out_date_auto_synced = Boolean(
            scopedOptions.checkOutDateAutoSynced,
        );
    }

    if (scopedOptions.sourceCheckOutDateAutoSynced !== undefined) {
        result.source_check_out_date_auto_synced = Boolean(
            scopedOptions.sourceCheckOutDateAutoSynced,
        );
    }

    return result;
}
