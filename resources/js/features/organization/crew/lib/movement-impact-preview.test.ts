import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { getMovementActionConfig } from '../actions/movement-action-config.ts';
import type {
    CrewAssignmentFormOptions,
    CrewMovementActionFormData,
    CrewMovementContext,
} from '../types.ts';
import { buildMovementImpactPreview } from './movement-impact-preview.ts';

const baseContext = {
    assignment_id: 1,
    assignment_no: 'CA-2026-000041',
    employee_id: 1,
    employee_name: 'Abdul Hamid',
    employee_no: 'EMP-001',
    current_phase_code: 'p4',
    current_phase_label: 'On Vessel',
    current_phase_started_at: '2026-09-10T08:00:00',
    days_in_phase: 7,
    days_onboard: 7,
    days_in_training: null,
    vessel_id: 10,
    vessel_name: 'Sea Eagle',
    rank_id: 3,
    rank_name: 'AB',
    client_id: 1,
    client_name: 'Client A',
    planned_join_at: null,
    planned_signoff_at: null,
    planned_travel_at: null,
    actual_join_at: '2026-09-10T08:00:00',
    actual_disembarkation_at: null,
    training_provider: null,
    training_course: null,
    training_course_id: null,
    sync_training_enabled: false,
    training_started_at: null,
    training_expected_completion_at: null,
    company_timezone: 'Asia/Dubai',
    tour_of_duty_days: null,
    planned_signoff_source: null,
    planned_signoff_source_label: null,
    current_duty_day: null,
    remaining_tour_days: null,
    tour_progress_percent: null,
    tour_progress_display_percent: null,
    tour_status: null,
    tour_status_label: null,
    tour_status_severity: null,
    pre_join_accommodation: undefined,
    post_signoff_accommodation: undefined,
    active_on_vessel_elsewhere: null,
} satisfies CrewMovementContext;

const formOptions: CrewAssignmentFormOptions = {
    vessels: [
        { id: 10, name: 'Sea Eagle', client_id: 1 },
        { id: 11, name: 'Sea Falcon', client_id: 1 },
    ],
    ranks: [],
    clients: [],
    courses: [],
    hotels: [],
    room_types: [],
};

function buildForm(
    action: CrewMovementActionFormData['action'],
    overrides: Partial<CrewMovementActionFormData> = {},
): CrewMovementActionFormData {
    return {
        action,
        occurred_at: '2026-09-17T11:30',
        next_phase: '',
        starting_phase: '',
        provider: '',
        course: '',
        course_id: null,
        planned_start_at: '',
        planned_end_at: '',
        remarks: '',
        vessel_id: 11,
        rank_id: 3,
        client_id: 1,
        planned_signoff_at: '',
        planned_travel_at: '',
        reason: '',
        planned_signoff_choice: 'manual_override',
        planned_signoff_override_reason: '',
        completion_intent: '',
        accommodation_status: '',
        hotel_id: null,
        room_type_id: null,
        check_in_date: '',
        check_out_date: '',
        source_check_out_date: '',
        no_hotel_accommodation: false,
        ...overrides,
    };
}

describe('buildMovementImpactPreview', () => {
    it('shows transfer source and destination correctly', () => {
        const config = getMovementActionConfig('transfer_vessel');
        const preview = buildMovementImpactPreview({
            action: 'transfer_vessel',
            config,
            context: baseContext,
            formData: buildForm('transfer_vessel'),
            formOptions,
        });

        assert.ok(preview);
        assert.match(preview!.subject ?? '', /Abdul Hamid/);
        assert.match(preview!.currentState ?? '', /Sea Eagle/);
        assert.equal(preview!.destinationState, 'Sea Falcon');
        assert.equal(preview!.severity, 'high');
        assert.equal(config.submitLabel, 'Confirm Transfer');
    });

    it('renders redeploy preview with assignment and destination vessel', () => {
        const context = {
            ...baseContext,
            current_phase_code: 'p5',
            current_phase_label: 'Demobilisation Standby',
        };
        const config = getMovementActionConfig('redeploy');
        const preview = buildMovementImpactPreview({
            action: 'redeploy',
            config,
            context,
            formData: buildForm('redeploy'),
            formOptions,
        });

        assert.equal(preview?.subject, 'CA-2026-000041');
        assert.match(preview?.currentState ?? '', /P5/);
        assert.equal(preview?.destinationState, 'Sea Falcon');
    });

    it('explains confirm disembarkation and distinguishes planned sign-off', () => {
        const config = getMovementActionConfig('confirm_disembarkation');
        const preview = buildMovementImpactPreview({
            action: 'confirm_disembarkation',
            config,
            context: baseContext,
            formData: buildForm('confirm_disembarkation', { next_phase: 'p5' }),
        });

        assert.match(preview?.impacts?.join(' ') ?? '', /P4 On Vessel ends/i);
        assert.match(
            preview?.warning ?? '',
            /Planning a Sign-Off does not disembark/i,
        );
    });

    it('shows travel home impact', () => {
        const config = getMovementActionConfig('travel_home');
        const preview = buildMovementImpactPreview({
            action: 'travel_home',
            config,
            context: {
                ...baseContext,
                current_phase_code: 'p5',
                current_phase_label: 'Demobilisation Standby',
            },
            formData: buildForm('travel_home'),
        });

        assert.match(
            preview?.impacts?.join(' ') ?? '',
            /Demobilisation standby ends/i,
        );
    });

    it('shows close assignment impact', () => {
        const config = getMovementActionConfig('close_assignment');
        const preview = buildMovementImpactPreview({
            action: 'close_assignment',
            config,
            context: {
                ...baseContext,
                current_phase_code: 'p6',
                current_phase_label: 'Home / Redeployment',
            },
            formData: buildForm('close_assignment'),
        });

        assert.match(preview?.impacts?.join(' ') ?? '', /Completed/i);
        assert.equal(config.submitLabel, 'Close Assignment');
    });

    it('uses destructive presentation for cancel assignment', () => {
        const config = getMovementActionConfig('cancel_assignment');
        const preview = buildMovementImpactPreview({
            action: 'cancel_assignment',
            config,
            context: baseContext,
            formData: buildForm('cancel_assignment'),
        });

        assert.equal(preview?.severity, 'destructive');
        assert.match(preview?.subject ?? '', /CA-2026-000041/);
        assert.match(preview?.subject ?? '', /Abdul Hamid/);
    });

    it('uses lightweight preview for join vessel', () => {
        const config = getMovementActionConfig('join_vessel');
        const preview = buildMovementImpactPreview({
            action: 'join_vessel',
            config,
            context: {
                ...baseContext,
                current_phase_code: 'p3',
                current_phase_label: 'Ready to Join',
            },
            formData: buildForm('join_vessel'),
            formOptions,
        });

        assert.equal(preview?.compact, true);
        assert.match(preview?.impacts?.[0] ?? '', /P4 · On Vessel/);
        assert.equal(config.submitLabel, 'Confirm Join');
    });

    it('uses lightweight preview for start assignment', () => {
        const config = getMovementActionConfig('approve_mobilisation');
        const preview = buildMovementImpactPreview({
            action: 'approve_mobilisation',
            config,
            context: {
                ...baseContext,
                current_phase_code: 'p0',
                current_phase_label: 'Pre-Mobilisation',
            },
            formData: buildForm('approve_mobilisation'),
        });

        assert.equal(preview?.compact, true);
        assert.match(preview?.impacts?.[0] ?? '', /Pre-Mobilisation/);
    });

    it('does not add impact preview for plan sign-off', () => {
        const config = getMovementActionConfig('plan_signoff');
        const preview = buildMovementImpactPreview({
            action: 'plan_signoff',
            config,
            context: baseContext,
            formData: buildForm('plan_signoff'),
        });

        assert.equal(preview, null);
        assert.equal(config.impactPreview, 'none');
    });
});
