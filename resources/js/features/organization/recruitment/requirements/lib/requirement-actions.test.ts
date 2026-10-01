import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { visibleRequirementActions } from './requirement-actions.ts';

describe('visibleRequirementActions', () => {
    it('exposes approval workflow actions from row capabilities', () => {
        const actions = visibleRequirementActions({
            can_edit: false,
            can_submit: true,
            can_approve: false,
            can_return: false,
            can_resubmit: false,
            can_change_headcount: false,
            can_extend: false,
            can_hold: false,
            can_resume: false,
            can_fill: false,
            can_reopen: false,
            can_repeat: false,
            can_cancel: true,
        });

        assert.equal(actions.canSubmit, true);
        assert.equal(actions.canApprove, false);
        assert.equal(actions.canReturn, false);
    });

    it('shows approve and return for assigned approver', () => {
        const actions = visibleRequirementActions({
            can_edit: false,
            can_submit: false,
            can_approve: true,
            can_return: true,
            can_resubmit: false,
            can_change_headcount: false,
            can_extend: false,
            can_hold: false,
            can_resume: false,
            can_fill: false,
            can_reopen: false,
            can_repeat: false,
            can_cancel: false,
        });

        assert.equal(actions.canApprove, true);
        assert.equal(actions.canReturn, true);
    });
});
