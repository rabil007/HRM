import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { LEAVE_REQUEST_STATUS_SUMMARY_LABELS } from '../lib/leave-request-status-summary.ts';

describe('My Leave status summary cards', () => {
    it('shows Pending, Approved, Rejected, and Cancelled without All', () => {
        assert.deepEqual(
            [...LEAVE_REQUEST_STATUS_SUMMARY_LABELS],
            ['Pending', 'Approved', 'Rejected', 'Cancelled'],
        );
        assert.ok(
            !LEAVE_REQUEST_STATUS_SUMMARY_LABELS.some(
                (label) => label === 'All',
            ),
        );
    });
});
