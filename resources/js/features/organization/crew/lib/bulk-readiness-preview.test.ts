import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildAssignmentReadinessGuidance } from './assignment-readiness-guidance.ts';
import type { BulkPreviewRowInput } from './bulk-readiness-preview.ts';
import {
    buildBulkAttentionList,
    buildBulkPreviewRows,
    bulkStartHelperText,
    classifyBulkRow,
    indicesOfBlockedRows,
    removeBlockedBulkRows,
    resolveNextPreviewRowKey,
    resolvePreviewIndex,
} from './bulk-readiness-preview.ts';
import { canSubmitBulkBatch, summarizeBulkRows } from './bulk-row-status.ts';
import { isBulkCreateMode } from './crew-assignment-create-mode.ts';

const formOptions = {
    employees: [
        {
            id: 10,
            name: 'Abdul Hamid Kadir',
            employee_no: '2073',
            rank_id: 1,
        },
        {
            id: 11,
            name: 'John Mathew',
            employee_no: '2088',
            rank_id: 1,
        },
        {
            id: 12,
            name: 'Mohammed Ali',
            employee_no: '2099',
            rank_id: 1,
        },
    ],
    ranks: [{ id: 1, name: 'Able Seaman' }],
    vessels: [
        { id: 5, name: 'Sea Eagle' },
        { id: 8, name: 'Sea Falcon' },
    ],
    clients: [{ id: 1, name: 'ADNOC' }],
    courses: [],
    company_timezone: 'Asia/Dubai',
    max_home_days: 30,
    employee_status_by_employee: {
        '10': {
            status: 'on_vessel',
            label: 'On Vessel',
            current_phase: 'p4',
            current_vessel: 'Sea Eagle',
            assignment_id: 42,
            assignment_no: 'CA-2026-000042',
            since: null,
            days_in_phase: 98,
            planned_next_date: null,
            warning: null,
            in_home_days: null,
            vessel_name: 'Sea Eagle',
            has_active_assignment: true,
        },
        '11': {
            status: 'join_standby',
            label: 'Join Standby',
            current_phase: 'p2a',
            current_vessel: 'Sea Eagle',
            assignment_id: 43,
            assignment_no: 'CA-2026-000043',
            since: null,
            days_in_phase: 2,
            planned_next_date: null,
            warning: null,
            in_home_days: null,
            vessel_name: 'Sea Eagle',
            has_active_assignment: true,
        },
        '12': {
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
            in_home_days: 34,
            days_at_home: 34,
            availability_status: 'over_limit',
            vessel_name: null,
            has_active_assignment: false,
        },
    },
    active_on_vessel_by_employee: {
        '10': {
            assignment_id: 42,
            assignment_no: 'CA-2026-000042',
            employee_id: 10,
            employee_name: 'Abdul Hamid Kadir',
            vessel_id: 5,
            vessel_name: 'Sea Eagle',
            phase_id: 99,
            actual_start_at: null,
            actual_start_display: null,
            status: 'on_vessel',
            can_transfer: true,
        },
    },
};

const rows: BulkPreviewRowInput[] = [
    {
        key: 'row-1',
        employee_id: 10,
        rank_id: 1,
    },
    {
        key: 'row-2',
        employee_id: 11,
        rank_id: 1,
    },
    {
        key: 'row-3',
        employee_id: 12,
        rank_id: 1,
    },
    {
        key: 'row-4',
        employee_id: null,
        rank_id: null,
    },
];

const permissions = {
    view: true,
    update: true,
    perform_movement: true,
    cancel: true,
    view_planning: true,
};

describe('bulk readiness preview helpers', () => {
    it('classifies rows as ready, blocked, or incomplete', () => {
        assert.equal(
            classifyBulkRow(10, formOptions.employee_status_by_employee['10']),
            'blocked',
        );
        assert.equal(
            classifyBulkRow(12, formOptions.employee_status_by_employee['12']),
            'ready',
        );
        assert.equal(classifyBulkRow(null, null), 'incomplete');
    });

    it('summarizes bulk counts consistently with submission logic', () => {
        const summary = summarizeBulkRows(rows, (employeeId) =>
            employeeId == null
                ? null
                : (formOptions.employee_status_by_employee[
                      String(employeeId)
                  ] ?? null),
        );

        assert.equal(summary.readyCount, 1);
        assert.equal(summary.blockedCount, 2);
        assert.equal(summary.incompleteCount, 1);
    });

    it('lists only blocked and incomplete employees in attention list', () => {
        const attention = buildBulkAttentionList(rows, formOptions);

        assert.equal(attention.length, 3);
        assert.ok(attention.every((item) => item.state !== 'ready'));
        assert.equal(attention[0]?.employeeName, 'Abdul Hamid Kadir');
    });

    it('filters preview rows to issues only', () => {
        const allRows = buildBulkPreviewRows(rows, formOptions, 'all');
        const issueRows = buildBulkPreviewRows(rows, formOptions, 'issues');

        assert.equal(allRows.length, 4);
        assert.equal(issueRows.length, 3);
    });

    it('resolves preview index from row key', () => {
        const previewRows = buildBulkPreviewRows(rows, formOptions, 'all');

        assert.equal(resolvePreviewIndex(previewRows, 'row-2'), 1);
    });

    it('selects the next preview row after removal', () => {
        const remaining = rows.filter((row) => row.key !== 'row-2');

        assert.equal(
            resolveNextPreviewRowKey(remaining, formOptions, 1, 'all'),
            'row-3',
        );
    });

    it('shows transfer vessel for blocked P4 preview when permitted', () => {
        const previewRow = buildBulkPreviewRows(
            rows,
            formOptions,
            'issues',
        )[0]!;

        const guidance = buildAssignmentReadinessGuidance({
            status: previewRow.status,
            activeOnVessel: previewRow.activeOnVessel,
            destinationVesselId: 8,
            destinationVesselName: 'Sea Falcon',
            plannedJoinAt: null,
            employeeName: 'Abdul',
            permissions,
            vessels: formOptions.vessels,
            maxHomeDays: 30,
        });

        assert.ok(
            guidance?.actions.some(
                (action) => action.label === 'Transfer to Sea Falcon',
            ),
        );
    });

    it('does not expose transfer vessel for P2A preview', () => {
        const previewRow = buildBulkPreviewRows(
            rows,
            formOptions,
            'issues',
        )[1]!;

        const guidance = buildAssignmentReadinessGuidance({
            status: previewRow.status,
            activeOnVessel: previewRow.activeOnVessel,
            destinationVesselId: 8,
            destinationVesselName: 'Sea Falcon',
            plannedJoinAt: null,
            employeeName: 'John',
            permissions,
            vessels: formOptions.vessels,
            maxHomeDays: 30,
        });

        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
    });

    it('hides transfer when movement permission is missing', () => {
        const previewRow = buildBulkPreviewRows(
            rows,
            formOptions,
            'issues',
        )[0]!;

        const guidance = buildAssignmentReadinessGuidance({
            status: previewRow.status,
            activeOnVessel: previewRow.activeOnVessel,
            destinationVesselId: null,
            destinationVesselName: null,
            plannedJoinAt: null,
            employeeName: 'Abdul',
            permissions: {
                ...permissions,
                perform_movement: false,
            },
            vessels: formOptions.vessels,
            maxHomeDays: 30,
        });

        assert.equal(
            guidance?.actions.some(
                (action) => action.key === 'transfer_vessel',
            ),
            false,
        );
    });

    it('returns helper text when blocked or incomplete rows remain', () => {
        assert.match(
            bulkStartHelperText({
                readyCount: 6,
                blockedCount: 2,
                incompleteCount: 0,
            }) ?? '',
            /2 crew members need attention/,
        );
        assert.match(
            bulkStartHelperText({
                readyCount: 6,
                blockedCount: 0,
                incompleteCount: 1,
            }) ?? '',
            /1 crew row is incomplete/,
        );
    });

    it('finds blocked row indices for remove-blocked action', () => {
        assert.deepEqual(indicesOfBlockedRows(rows, formOptions), [0, 1]);
    });

    it('removes only blocked rows and keeps ready rows', () => {
        const removal = removeBlockedBulkRows(rows, formOptions);

        assert.ok(removal);
        assert.equal(removal.rows.length, 2);
        assert.equal(removal.ensureMinimumOneRow, false);
        assert.equal(removal.rows[0]?.employee_id, 12);
        assert.equal(removal.rows[1]?.employee_id, null);
    });

    it('requires a blank row when all blocked rows are removed', () => {
        const blockedOnly: BulkPreviewRowInput[] = [
            { key: 'row-a', employee_id: 10, rank_id: 1 },
            { key: 'row-b', employee_id: 11, rank_id: 1 },
        ];

        const removal = removeBlockedBulkRows(blockedOnly, formOptions);

        assert.ok(removal);
        assert.equal(removal.rows.length, 0);
        assert.equal(removal.ensureMinimumOneRow, true);
    });

    it('returns null when no blocked rows remain to remove', () => {
        const readyOnly: BulkPreviewRowInput[] = [
            { key: 'row-ready', employee_id: 12, rank_id: 1 },
        ];

        assert.equal(removeBlockedBulkRows(readyOnly, formOptions), null);
    });

    it('leaves single mode after removing all blocked rows from a two-row batch', () => {
        const blockedOnly: BulkPreviewRowInput[] = [
            { key: 'row-a', employee_id: 10, rank_id: 1 },
            { key: 'row-b', employee_id: 11, rank_id: 1 },
        ];
        const removal = removeBlockedBulkRows(blockedOnly, formOptions);
        const nextRows =
            removal?.ensureMinimumOneRow === true
                ? [{ key: 'row-blank', employee_id: null, rank_id: null }]
                : (removal?.rows ?? []);

        assert.equal(isBulkCreateMode(nextRows.length), false);
    });

    it('does not keep removed preview row keys after blocked removal', () => {
        const blockedOnly: BulkPreviewRowInput[] = [
            { key: 'row-a', employee_id: 10, rank_id: 1 },
            { key: 'row-b', employee_id: 11, rank_id: 1 },
        ];
        const removal = removeBlockedBulkRows(blockedOnly, formOptions);
        const nextRows =
            removal?.ensureMinimumOneRow === true
                ? [{ key: 'row-blank', employee_id: null, rank_id: null }]
                : (removal?.rows ?? []);
        const previewRowKey = null;

        assert.equal(
            nextRows.some((row) => row.key === previewRowKey),
            false,
        );
        assert.ok(
            nextRows.every((row) => row.key !== 'row-a' && row.key !== 'row-b'),
        );
    });

    it('keeps submission disabled when only a blank row remains', () => {
        const blankOnly = [{ employee_id: null, rank_id: null }];
        const summary = summarizeBulkRows(blankOnly, () => null);

        assert.equal(summary.incompleteCount, 1);
        assert.equal(canSubmitBulkBatch(summary), false);
    });

    it('still allows a ready row after removing one blocked row from a mixed batch', () => {
        const mixed: BulkPreviewRowInput[] = [
            { key: 'row-blocked', employee_id: 10, rank_id: 1 },
            { key: 'row-ready-a', employee_id: 12, rank_id: 1 },
            { key: 'row-ready-b', employee_id: 12, rank_id: 1 },
        ];
        const removal = removeBlockedBulkRows(mixed, formOptions);

        assert.ok(removal);
        assert.equal(removal.rows.length, 2);
        assert.equal(removal.ensureMinimumOneRow, false);
        assert.equal(
            summarizeBulkRows(removal.rows, (employeeId) =>
                employeeId == null
                    ? null
                    : (formOptions.employee_status_by_employee[
                          String(employeeId)
                      ] ?? null),
            ).readyCount,
            2,
        );
    });
});
