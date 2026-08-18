import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { LeaveRequest } from '../types.ts';
import { leaveRequestMobileCardModel } from './leave-request-mobile-card.ts';

function leaveRequest(overrides: Partial<LeaveRequest> = {}): LeaveRequest {
    return {
        id: 9,
        employee: { id: 12, employee_no: 'EMP-0012', name: 'Mohammed Rabil' },
        leave_type: {
            id: 1,
            name: 'Annual Leave',
            code: 'AL',
            color: '#2563eb',
        },
        start_date: '2026-08-22',
        end_date: '2026-08-30',
        total_days: 9,
        reason: null,
        status: 'pending',
        rejection_reason: null,
        cancellation_reason: null,
        decided_at: null,
        approver: null,
        created_at: '2026-08-01',
        attachments: [],
        can_approve_current_step: false,
        can_edit: false,
        can_cancel: false,
        can_delete: false,
        can_administratively_delete: false,
        ...overrides,
    };
}

describe('leaveRequestMobileCardModel', () => {
    it('renders pending identity, dates, duration, and status', () => {
        const model = leaveRequestMobileCardModel(leaveRequest());

        assert.equal(model.title, 'Mohammed Rabil');
        assert.equal(model.subtitle, 'Annual Leave');
        assert.equal(model.startDate, '2026-08-22');
        assert.equal(model.endDate, '2026-08-30');
        assert.equal(model.duration, '9 days');
        assert.equal(model.status, 'pending');
        assert.equal(model.showApprove, false);
        assert.equal(model.primaryLabel, 'Open');
    });

    it('shows approved and rejected statuses without approval actions', () => {
        const approved = leaveRequestMobileCardModel(
            leaveRequest({
                status: 'approved',
                can_approve_current_step: true,
            }),
        );
        const rejected = leaveRequestMobileCardModel(
            leaveRequest({
                status: 'rejected',
                can_approve_current_step: true,
            }),
        );

        assert.equal(approved.status, 'approved');
        assert.equal(approved.showApprove, false);
        assert.equal(rejected.status, 'rejected');
        assert.equal(rejected.showApprove, false);
    });

    it('exposes approval only when the current user may act on the pending step', () => {
        const permitted = leaveRequestMobileCardModel(
            leaveRequest({ can_approve_current_step: true }),
        );
        const denied = leaveRequestMobileCardModel(
            leaveRequest({ can_approve_current_step: false }),
        );

        assert.equal(permitted.showApprove, true);
        assert.equal(permitted.showReject, true);
        assert.equal(permitted.primaryLabel, 'Approve');
        assert.equal(denied.showApprove, false);
        assert.equal(denied.primaryLabel, 'Open');
    });
});
