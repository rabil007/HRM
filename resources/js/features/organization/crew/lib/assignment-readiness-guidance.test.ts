import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildAssignmentReadinessGuidance } from './assignment-readiness-guidance.ts';

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
    days_at_home?: number | null;
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

const vessels = [
    { id: 5, name: 'Sea Eagle' },
    { id: 8, name: 'Sea Falcon' },
];

const fullPermissions = {
    view: true,
    update: true,
    perform_movement: true,
    cancel: true,
    view_planning: true,
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
        in_home_days: 6,
        vessel_name: null,
        has_active_assignment: false,
        ...overrides,
    };
}

function buildContext(
    overrides: Partial<{
        status: EmployeeOperationalStatus | null;
        activeOnVessel: ActiveOnVesselAssignment | null;
        destinationVesselId: number | null;
        plannedJoinAt: string | null;
        permissions: typeof fullPermissions;
    }> = {},
) {
    return {
        status: overrides.status ?? null,
        activeOnVessel: overrides.activeOnVessel ?? null,
        destinationVesselId: overrides.destinationVesselId ?? null,
        destinationVesselName:
            overrides.destinationVesselId === 8 ? 'Sea Falcon' : null,
        plannedJoinAt: overrides.plannedJoinAt ?? null,
        employeeName: 'Abdul',
        permissions: overrides.permissions ?? fullPermissions,
        vessels,
        maxHomeDays: 30,
    };
}

function activeOnVessel(
    overrides: Partial<ActiveOnVesselAssignment> = {},
): ActiveOnVesselAssignment {
    return {
        assignment_id: 42,
        assignment_no: 'CA-2026-000042',
        employee_id: 10,
        employee_name: 'Abdul',
        vessel_id: 5,
        vessel_name: 'Sea Eagle',
        phase_id: 99,
        actual_start_at: '2026-09-02T08:00:00+04:00',
        actual_start_display: '02 Sep 2026 08:00',
        status: 'on_vessel',
        can_transfer: true,
        ...overrides,
    };
}

describe('buildAssignmentReadinessGuidance', () => {
    it('returns available guidance when no active assignment exists', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'in_home',
                    days_at_home: 6,
                    availability_status: 'within_limit',
                }),
            }),
        );

        assert.equal(guidance?.phaseLabel, 'AVAILABLE');
        assert.equal(guidance?.severity, 'info');
        assert.match(
            guidance?.explanation ?? '',
            /Available for a new mobilisation/,
        );
    });

    it('shows home availability within the configured limit', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'in_home',
                    days_at_home: 6,
                    availability_status: 'within_limit',
                }),
            }),
        );

        assert.match(guidance?.summaryLine ?? '', /Home: 6 days/);
    });

    it('shows over-target home availability without blocking tone', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'in_home',
                    days_at_home: 34,
                    availability_status: 'over_limit',
                }),
            }),
        );

        assert.match(guidance?.summaryLine ?? '', /34 days/);
        assert.match(guidance?.explanation ?? '', /4 days over target/);
    });

    it('guides active P0 toward continuing the current mobilisation', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'pre_mobilisation',
                    label: 'Pre-Mobilisation',
                    current_phase: 'p0',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                }),
            }),
        );

        assert.match(guidance?.phaseLabel ?? '', /P0 · Pre-Mobilisation/);
        assert.ok(
            guidance?.actions.some(
                (action) => action.key === 'continue_assignment',
            ),
        );
        assert.ok(
            guidance?.actions.some(
                (action) => action.key === 'edit_mobilisation',
            ),
        );
        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
    });

    it('guides active P2A without transfer suggestions', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'join_standby',
                    label: 'Join Standby',
                    current_phase: 'p2a',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                destinationVesselId: 8,
            }),
        );

        assert.match(guidance?.phaseLabel ?? '', /P2A · Join Standby/);
        assert.equal(guidance?.destinationAdvisory?.severity, 'attention');
        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
    });

    it('guides active P2B without transfer suggestions', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'training',
                    label: 'Training',
                    current_phase: 'p2b',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                destinationVesselId: 8,
            }),
        );

        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
        assert.equal(
            guidance?.destinationAdvisory?.title,
            'Destination changed',
        );
    });

    it('guides active P3 toward join vessel workflow', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'ready_to_join',
                    label: 'Ready to Join',
                    current_phase: 'p3',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                }),
                destinationVesselId: 8,
            }),
        );

        assert.match(guidance?.phaseLabel ?? '', /P3 · Ready to Join/);
        assert.ok(
            guidance?.actions.some((action) => action.label === 'Join Vessel'),
        );
        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
    });

    it('shows transfer vessel for P4 without a selected destination', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    current_phase: 'p4',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                activeOnVessel: activeOnVessel(),
                destinationVesselId: null,
            }),
        );

        const transfer = guidance?.actions.find(
            (action) => action.key === 'transfer_vessel',
        );

        assert.ok(transfer);
        assert.equal(transfer?.label, 'Transfer Vessel');
    });

    it('shows transfer to destination when P4 and different vessel selected', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    current_phase: 'p4',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                activeOnVessel: activeOnVessel(),
                destinationVesselId: 8,
            }),
        );

        assert.equal(guidance?.destinationAdvisory?.severity, 'conflict');
        assert.ok(
            guidance?.actions.some(
                (action) => action.label === 'Transfer to Sea Falcon',
            ),
        );
    });

    it('does not duplicate intent cards for compact P4 guidance', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    current_phase: 'p4',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                activeOnVessel: activeOnVessel(),
            }),
        );

        const openActions = guidance?.actions.filter(
            (action) => action.key === 'open_assignment',
        );

        assert.equal(openActions?.length, 1);
        assert.ok(guidance?.actions.length <= 3);
    });

    it('guides active P5 toward return home or redeploy', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'demob_standby',
                    label: 'Demobilisation Standby',
                    current_phase: 'p5',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                }),
            }),
        );

        assert.match(guidance?.phaseLabel ?? '', /P5 · Demobilisation Standby/);
        assert.ok(
            guidance?.actions.some((action) => action.label === 'Return Home'),
        );
    });

    it('guides active P6 toward close, redeploy, or planning', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'home_redeploy',
                    label: 'Home / Redeployment',
                    current_phase: 'p6',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                }),
            }),
        );

        assert.match(guidance?.phaseLabel ?? '', /P6 · Home/);
        assert.ok(
            guidance?.actions.some((action) => action.label === 'Redeploy'),
        );
    });

    it('uses change-mobilisation guidance for pre-P4 destination differences', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'join_standby',
                    label: 'Join Standby',
                    current_phase: 'p2a',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                destinationVesselId: 8,
            }),
        );

        assert.equal(
            guidance?.destinationAdvisory?.title,
            'Destination changed',
        );
        assert.match(
            guidance?.destinationAdvisory?.message ?? '',
            /Update this mobilisation/i,
        );
    });

    it('warns when planned join is before current planned sign-off', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    current_phase: 'p4',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                    planned_next_date: '2026-11-08',
                }),
                activeOnVessel: activeOnVessel(),
                plannedJoinAt: '2026-11-01',
            }),
        );

        assert.equal(
            guidance?.plannedDateAdvisory?.title,
            'Planned date overlap',
        );
    });

    it('hides restricted assignment actions for users without view permission', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'join_standby',
                    label: 'Join Standby',
                    current_phase: null,
                    has_active_assignment: true,
                    assignment_id: null,
                    assignment_no: null,
                }),
                permissions: {
                    ...fullPermissions,
                    view: false,
                },
            }),
        );

        assert.equal(guidance?.phaseLabel, 'ACTIVE ASSIGNMENT');
        assert.equal(guidance?.actions.length, 0);
    });

    it('omits transfer action when movement permission is missing', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    current_phase: 'p4',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                    vessel_name: 'Sea Eagle',
                }),
                activeOnVessel: activeOnVessel(),
                destinationVesselId: 8,
                permissions: {
                    ...fullPermissions,
                    perform_movement: false,
                },
            }),
        );

        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
    });

    it('omits planning action when planning permission is missing', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'pre_mobilisation',
                    label: 'Pre-Mobilisation',
                    current_phase: 'p0',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                }),
                permissions: {
                    ...fullPermissions,
                    view_planning: false,
                },
            }),
        );

        assert.equal(
            guidance?.actions.some((action) => action.key === 'plan_future'),
            false,
        );
    });

    it('shows why-blocked help for active assignments', () => {
        const guidance = buildAssignmentReadinessGuidance(
            buildContext({
                status: makeStatus({
                    status: 'pre_mobilisation',
                    label: 'Pre-Mobilisation',
                    current_phase: 'p0',
                    has_active_assignment: true,
                    assignment_id: 42,
                    assignment_no: 'CA-2026-000042',
                }),
            }),
        );

        assert.equal(guidance?.showWhyBlocked, true);
        assert.match(
            guidance?.warning ?? '',
            /Another active assignment is blocked/,
        );
    });
});
