import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildAssignmentReadinessAttentionItems,
    buildAssignmentReadinessRecommendation,
    readinessStatusLabel,
    shouldShowTransferVesselSuggestion,
} from './assignment-readiness.ts';
type EmployeeOperationalStatus = {
    status: string;
    label: string;
    current_phase: string | null;
    current_vessel: string | null;
    assignment_id: number | null;
    assignment_no: string | null;
    since: string | null;
    days_in_phase: number | null;
    planned_next_date: string | null;
    warning: string | null;
    in_home_days: number | null;
    vessel_name: string | null;
    availability_status?: 'within_limit' | 'near_limit' | 'over_limit' | null;
    availability_detail?: string | null;
    has_active_assignment: boolean;
};

type ActiveOnVesselAssignment = {
    assignment_id: number;
    assignment_no: string;
    employee_id: number;
    employee_name: string;
    vessel_id: number | null;
    vessel_name: string | null;
    phase_id: number;
    actual_start_at: string | null;
    actual_start_display: string | null;
    status: string;
    can_transfer?: boolean;
};

function makeStatus(
    overrides: Partial<EmployeeOperationalStatus> = {},
): EmployeeOperationalStatus {
    return {
        status: 'in_home',
        label: 'Available',
        current_phase: null,
        current_vessel: null,
        assignment_id: null,
        assignment_no: null,
        since: null,
        days_in_phase: null,
        planned_next_date: null,
        warning: null,
        in_home_days: 12,
        vessel_name: null,
        has_active_assignment: false,
        ...overrides,
    };
}

describe('readinessStatusLabel', () => {
    it('maps operational statuses to concise labels', () => {
        assert.equal(readinessStatusLabel('on_vessel'), 'On Vessel');
        assert.equal(readinessStatusLabel('join_standby'), 'Standby');
        assert.equal(readinessStatusLabel('in_home'), 'Home');
    });
});

describe('buildAssignmentReadinessAttentionItems', () => {
    it('returns empty state when no employee status exists', () => {
        assert.deepEqual(
            buildAssignmentReadinessAttentionItems(null, null, null),
            [],
        );
    });

    it('warns when an active assignment exists', () => {
        const items = buildAssignmentReadinessAttentionItems(
            makeStatus({
                status: 'join_standby',
                has_active_assignment: true,
                assignment_no: 'CA-2026-000042',
                vessel_name: 'Ocean Star',
            }),
            null,
            null,
        );

        assert.ok(
            items.some((item) => item.id === 'existing-assignment'),
        );
        assert.ok(items.some((item) => item.id === 'potential-conflict'));
    });

    it('includes on-vessel guidance without duplicate conflict when transfer applies', () => {
        const activeOnVessel: ActiveOnVesselAssignment = {
            assignment_id: 1,
            assignment_no: 'CA-2026-000042',
            employee_id: 10,
            employee_name: 'Alex',
            vessel_id: 5,
            vessel_name: 'Ocean Star',
            phase_id: 99,
            actual_start_at: '2026-09-02T08:00:00+04:00',
            actual_start_display: '02 Sep 2026 08:00',
            status: 'on_vessel',
            can_transfer: true,
        };

        const items = buildAssignmentReadinessAttentionItems(
            makeStatus({
                status: 'on_vessel',
                has_active_assignment: true,
                assignment_no: 'CA-2026-000042',
                vessel_name: 'Ocean Star',
            }),
            activeOnVessel,
            8,
        );

        assert.ok(items.some((item) => item.id === 'on-vessel'));
        assert.equal(
            items.some((item) => item.id === 'potential-conflict'),
            false,
        );
    });

    it('includes home availability warnings from backend data', () => {
        const items = buildAssignmentReadinessAttentionItems(
            makeStatus({
                status: 'in_home',
                availability_status: 'over_limit',
                availability_detail: '6 days over availability limit',
            }),
            null,
            null,
        );

        assert.ok(items.some((item) => item.id === 'home-availability'));
    });
});

describe('buildAssignmentReadinessRecommendation', () => {
    it('recommends transfer vessel for on-vessel moves to another vessel', () => {
        const recommendation = buildAssignmentReadinessRecommendation(
            makeStatus({
                status: 'on_vessel',
                has_active_assignment: true,
            }),
            {
                assignment_id: 1,
                assignment_no: 'CA-2026-000042',
                employee_id: 10,
                employee_name: 'Alex',
                vessel_id: 5,
                vessel_name: 'Ocean Star',
                phase_id: 99,
                actual_start_at: null,
                actual_start_display: null,
                status: 'on_vessel',
            },
            8,
        );

        assert.match(
            recommendation?.title ?? '',
            /Transfer Vessel/i,
        );
    });

    it('recommends availability for home employees without active assignments', () => {
        const recommendation = buildAssignmentReadinessRecommendation(
            makeStatus({
                status: 'in_home',
                availability_status: 'within_limit',
            }),
            null,
            null,
        );

        assert.equal(recommendation?.title, 'Available for assignment');
        assert.equal(recommendation?.tone, 'positive');
    });
});

describe('shouldShowTransferVesselSuggestion', () => {
    it('only appears for on-vessel employees moving to another vessel', () => {
        assert.equal(
            shouldShowTransferVesselSuggestion(
                makeStatus({ status: 'on_vessel' }),
                {
                    assignment_id: 1,
                    assignment_no: 'CA-2026-000042',
                    employee_id: 10,
                    employee_name: 'Alex',
                    vessel_id: 5,
                    vessel_name: 'Ocean Star',
                    phase_id: 99,
                    actual_start_at: null,
                    actual_start_display: null,
                    status: 'on_vessel',
                },
                8,
            ),
            true,
        );

        assert.equal(
            shouldShowTransferVesselSuggestion(
                makeStatus({ status: 'on_vessel' }),
                {
                    assignment_id: 1,
                    assignment_no: 'CA-2026-000042',
                    employee_id: 10,
                    employee_name: 'Alex',
                    vessel_id: 5,
                    vessel_name: 'Ocean Star',
                    phase_id: 99,
                    actual_start_at: null,
                    actual_start_display: null,
                    status: 'on_vessel',
                },
                5,
            ),
            false,
        );
    });
});
