import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { EmployeeOperationalStatus } from '../types.ts';
import {
    bulkFieldError,
    bulkRowBlockReason,
    bulkRowIsBlocked,
    bulkRowIsIncomplete,
    canSubmitBulkBatch,
    summarizeBulkRows,
} from './bulk-row-status.ts';

function status(
    overrides: Partial<EmployeeOperationalStatus>,
): EmployeeOperationalStatus {
    return {
        status: 'available',
        label: 'Available',
        current_phase: null,
        current_vessel: null,
        assignment_id: null,
        assignment_no: null,
        since: null,
        days_in_phase: null,
        planned_next_date: null,
        warning: null,
        in_home_days: null,
        vessel_name: null,
        has_active_assignment: false,
        ...overrides,
    };
}

describe('bulkRowIsBlocked', () => {
    it('blocks employees with an active assignment', () => {
        assert.equal(
            bulkRowIsBlocked(status({ has_active_assignment: true })),
            true,
        );
        assert.equal(
            bulkRowIsBlocked(status({ has_active_assignment: false })),
            false,
        );
        assert.equal(bulkRowIsBlocked(null), false);
    });
});

describe('bulkRowBlockReason', () => {
    it('names the current vessel when On Vessel details are visible', () => {
        assert.equal(
            bulkRowBlockReason(
                status({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    has_active_assignment: true,
                    vessel_name: 'Vessel A',
                }),
                null,
            ),
            'On Vessel — already assigned to Vessel A. Use Transfer Vessel instead.',
        );
    });

    it('does not invent vessel details when they are restricted', () => {
        assert.equal(
            bulkRowBlockReason(
                status({
                    status: 'on_vessel',
                    label: 'On Vessel',
                    has_active_assignment: true,
                    vessel_name: null,
                    current_vessel: null,
                }),
                null,
            ),
            'On Vessel — already assigned. Use Transfer Vessel instead.',
        );
    });

    it('identifies other active assignment phases without exposing missing details', () => {
        assert.equal(
            bulkRowBlockReason(
                status({
                    status: 'join_standby',
                    label: 'Join Standby',
                    has_active_assignment: true,
                }),
                null,
            ),
            'Join Standby — this employee already has an active Crew Assignment.',
        );
    });
});

describe('bulkRowIsIncomplete', () => {
    it('treats rows without an employee as incomplete', () => {
        assert.equal(bulkRowIsIncomplete(null), true);
        assert.equal(bulkRowIsIncomplete(12), false);
    });
});

describe('summarizeBulkRows', () => {
    it('counts ready, blocked, and incomplete visible rows separately', () => {
        const summary = summarizeBulkRows(
            [{ employee_id: 1 }, { employee_id: null }, { employee_id: 2 }],
            (employeeId) => {
                if (employeeId === 2) {
                    return status({ has_active_assignment: true });
                }

                return status({ has_active_assignment: false });
            },
        );

        assert.deepEqual(summary, {
            readyCount: 1,
            blockedCount: 1,
            incompleteCount: 1,
        });
    });
});

describe('canSubmitBulkBatch', () => {
    it('allows submission only when every visible row is ready', () => {
        assert.equal(
            canSubmitBulkBatch({
                readyCount: 2,
                blockedCount: 0,
                incompleteCount: 0,
            }),
            true,
        );
        assert.equal(
            canSubmitBulkBatch({
                readyCount: 1,
                blockedCount: 0,
                incompleteCount: 1,
            }),
            false,
        );
        assert.equal(
            canSubmitBulkBatch({
                readyCount: 1,
                blockedCount: 1,
                incompleteCount: 0,
            }),
            false,
        );
        assert.equal(
            canSubmitBulkBatch({
                readyCount: 0,
                blockedCount: 0,
                incompleteCount: 0,
            }),
            false,
        );
    });
});

describe('bulkFieldError', () => {
    it('reads dotted Inertia validation keys', () => {
        assert.equal(
            bulkFieldError(
                {
                    'crew.2.employee_id':
                        'This employee has already been added to the batch.',
                },
                'crew.2.employee_id',
            ),
            'This employee has already been added to the batch.',
        );
    });
});
