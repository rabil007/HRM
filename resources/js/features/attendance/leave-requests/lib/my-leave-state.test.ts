import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { myLeaveState } from './my-leave-state.ts';

describe('myLeaveState', () => {
    it('explains a missing employee link before department eligibility', () => {
        const state = myLeaveState(null, false, true);

        assert.equal(
            state.title,
            'Your account is not linked to an employee record.',
        );
        assert.equal(
            state.description,
            'Ask HR or an administrator to link your user account to your employee profile before requesting leave.',
        );
        assert.equal(state.canRequestLeave, false);
        assert.equal(state.showBalances, false);
        assert.equal(myLeaveState(null, true, true).title, state.title);
    });

    it('explains an excluded department for a linked employee', () => {
        const state = myLeaveState(123, false, true);

        assert.equal(
            state.title,
            'Attendance & Leave is not enabled for your department.',
        );
        assert.equal(
            state.description,
            'Your current department is excluded from Attendance and Leave. Contact HR if this should be enabled.',
        );
        assert.equal(state.canRequestLeave, false);
        assert.equal(state.showBalances, false);
    });

    it('keeps eligible self-service available subject to permission', () => {
        const state = myLeaveState(123, true, true);

        assert.equal(state.title, 'You have no leave requests yet.');
        assert.equal(state.canRequestLeave, true);
        assert.equal(state.showBalances, true);
        assert.equal(myLeaveState(123, true, false).canRequestLeave, false);
    });
});
