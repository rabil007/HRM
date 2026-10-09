import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewMovementActionFormData } from '../types.ts';
import {
    ALLOWED_SCHEDULE_ACTION_FIELD_KEYS,
    buildScheduledMovementEditActionFields,
    buildScheduledMovementEditPayload,
    isAllowedScheduleActionFieldKey,
} from './scheduled-movement-edit-payload.ts';

function baseForm(
    overrides: Partial<CrewMovementActionFormData> = {},
): CrewMovementActionFormData {
    return {
        action: 'join_vessel',
        occurred_at: '2027-01-20 09:00',
        next_phase: 'p2a',
        starting_phase: 'p0',
        provider: 'Academy',
        course: 'STCW',
        course_id: 12,
        sync_training_to_employee_training: true,
        planned_start_at: '2027-01-18 09:00',
        planned_end_at: '2027-01-25',
        remarks: 'note',
        vessel_id: 3,
        position_id: 7,
        client_id: 2,
        planned_signoff_at: '2027-03-20',
        planned_travel_at: '2027-02-01',
        planned_arrival_at: '2027-01-15',
        reason: 'should-not-send',
        planned_signoff_choice: 'manual_override',
        planned_signoff_override_reason: 'Edit fields',
        completion_intent: 'close',
        accommodation_status: 'hotel',
        hotel_id: 9,
        room_type_id: 4,
        check_in_date: '2027-01-19',
        check_out_date: '2027-01-20',
        source_check_out_date: '2027-01-20',
        no_hotel_accommodation: false,
        ...overrides,
    };
}

describe('scheduled-movement-edit-payload', () => {
    it('allowlist includes training dates and sync option', () => {
        assert.equal(isAllowedScheduleActionFieldKey('planned_start_at'), true);
        assert.equal(isAllowedScheduleActionFieldKey('planned_end_at'), true);
        assert.equal(
            isAllowedScheduleActionFieldKey(
                'sync_training_to_employee_training',
            ),
            true,
        );
        assert.equal(
            isAllowedScheduleActionFieldKey('planned_arrival_at'),
            true,
        );
        assert.equal(isAllowedScheduleActionFieldKey('reason'), false);
        assert.equal(
            isAllowedScheduleActionFieldKey('planned_travel_at'),
            false,
        );
        assert.ok(
            ALLOWED_SCHEDULE_ACTION_FIELD_KEYS.includes('planned_start_at'),
        );
    });

    it('join vessel edit payload sends only join-relevant permitted fields', () => {
        const payload = buildScheduledMovementEditPayload(
            'join_vessel',
            baseForm({ action: 'join_vessel' }),
            {
                checkOutDateAutoSynced: true,
                sourceCheckOutDateAutoSynced: true,
            },
        );

        assert.equal(payload.scheduled_at, '2027-01-20 09:00');
        assert.deepEqual(Object.keys(payload.action_fields).sort(), [
            'check_out_date',
            'check_out_date_auto_synced',
            'client_id',
            'planned_signoff_at',
            'planned_signoff_choice',
            'planned_signoff_override_reason',
            'position_id',
            'remarks',
            'vessel_id',
        ]);
        assert.equal(payload.action_fields.vessel_id, 3);
        assert.equal(payload.action_fields.check_out_date_auto_synced, true);
        assert.equal(payload.check_out_date_auto_synced, true);
        assert.equal(payload.source_check_out_date_auto_synced, undefined);
        assert.equal(Object.hasOwn(payload.action_fields, 'provider'), false);
        assert.equal(
            Object.hasOwn(payload.action_fields, 'planned_start_at'),
            false,
        );
        assert.equal(Object.hasOwn(payload.action_fields, 'reason'), false);
        assert.equal(
            Object.hasOwn(payload.action_fields, 'accommodation_status'),
            false,
        );
    });

    it('send to training edit payload includes planned dates and omits hotel/join fields', () => {
        const fields = buildScheduledMovementEditActionFields(
            'send_to_training',
            baseForm({
                action: 'send_to_training',
                planned_start_at: '2027-01-18 09:00',
                planned_end_at: '2027-01-25',
            }),
            {
                checkOutDateAutoSynced: true,
                sourceCheckOutDateAutoSynced: true,
            },
        );

        assert.deepEqual(Object.keys(fields).sort(), [
            'course',
            'course_id',
            'planned_end_at',
            'planned_start_at',
            'provider',
            'remarks',
        ]);
        assert.equal(fields.planned_start_at, '2027-01-18 09:00');
        assert.equal(fields.planned_end_at, '2027-01-25');
        assert.equal(
            Object.hasOwn(fields, 'check_out_date_auto_synced'),
            false,
        );
        assert.equal(Object.hasOwn(fields, 'vessel_id'), false);
    });

    it('complete training edit payload includes sync option', () => {
        const fields = buildScheduledMovementEditActionFields(
            'complete_training',
            baseForm({
                action: 'complete_training',
                next_phase: 'p2a',
                sync_training_to_employee_training: false,
            }),
        );

        assert.equal(fields.sync_training_to_employee_training, false);
        assert.equal(fields.next_phase, 'p2a');
        assert.equal(Object.hasOwn(fields, 'planned_start_at'), false);
    });

    it('confirm disembarkation edit payload keeps next phase and hotel fields only', () => {
        const payload = buildScheduledMovementEditPayload(
            'confirm_disembarkation',
            baseForm({
                action: 'confirm_disembarkation',
                next_phase: 'p5',
                accommodation_status: 'no_accommodation',
                hotel_id: null,
                room_type_id: null,
                check_in_date: '',
            }),
            {
                checkOutDateAutoSynced: false,
                sourceCheckOutDateAutoSynced: false,
            },
        );

        assert.deepEqual(Object.keys(payload.action_fields).sort(), [
            'accommodation_status',
            'check_in_date',
            'hotel_id',
            'next_phase',
            'remarks',
            'room_type_id',
        ]);
        assert.equal(payload.action_fields.next_phase, 'p5');
        assert.equal(payload.check_out_date_auto_synced, undefined);
        assert.equal(
            Object.hasOwn(payload.action_fields, 'completion_intent'),
            false,
        );
    });

    it('return home edit payload keeps completion intent and optional checkout', () => {
        const fields = buildScheduledMovementEditActionFields(
            'travel_home',
            baseForm({
                action: 'travel_home',
                completion_intent: 'close',
                check_out_date: '2027-02-02',
            }),
            { checkOutDateAutoSynced: false },
        );

        assert.deepEqual(Object.keys(fields).sort(), [
            'check_out_date',
            'check_out_date_auto_synced',
            'completion_intent',
            'remarks',
        ]);
        assert.equal(fields.completion_intent, 'close');
        assert.equal(fields.check_out_date_auto_synced, false);
        assert.equal(Object.hasOwn(fields, 'planned_travel_at'), false);
    });

    it('omits checkout sync keys when checkout was removed from cleaned payload', () => {
        const form = baseForm({ action: 'join_vessel' });
        delete (form as { check_out_date?: string }).check_out_date;

        const fields = buildScheduledMovementEditActionFields(
            'join_vessel',
            form,
            { checkOutDateAutoSynced: false },
        );

        assert.equal(Object.hasOwn(fields, 'check_out_date'), false);
        assert.equal(fields.check_out_date_auto_synced, false);
        assert.equal(fields.vessel_id, 3);
    });
});
