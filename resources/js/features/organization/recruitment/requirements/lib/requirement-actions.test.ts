import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    requirementPrimaryWorkflowActionLabel,
    resolveRequirementPrimaryWorkflowAction,
    visibleRequirementActions,
} from './requirement-actions.ts';

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

describe('resolveRequirementPrimaryWorkflowAction', () => {
    const none = {
        can_approve: false,
        can_submit: false,
        can_resubmit: false,
        can_resume: false,
        can_fill: false,
    };

    it('returns only one primary action even when multiple flags are true', () => {
        assert.equal(
            resolveRequirementPrimaryWorkflowAction({
                ...none,
                can_approve: true,
                can_submit: true,
                can_fill: true,
            }),
            'approve',
        );
        assert.equal(
            resolveRequirementPrimaryWorkflowAction({
                ...none,
                can_submit: true,
                can_fill: true,
            }),
            'submit',
        );
        assert.equal(
            resolveRequirementPrimaryWorkflowAction({
                ...none,
                can_resubmit: true,
                can_fill: true,
            }),
            'resubmit',
        );
        assert.equal(
            resolveRequirementPrimaryWorkflowAction({
                ...none,
                can_resume: true,
                can_fill: true,
            }),
            'resume',
        );
        assert.equal(
            resolveRequirementPrimaryWorkflowAction({
                ...none,
                can_fill: true,
            }),
            'fill',
        );
    });

    it('respects permission flags and returns null when none apply', () => {
        assert.equal(resolveRequirementPrimaryWorkflowAction(none), null);
        assert.equal(
            requirementPrimaryWorkflowActionLabel('fill'),
            'Mark as filled',
        );
        assert.equal(
            requirementPrimaryWorkflowActionLabel('submit'),
            'Submit for approval',
        );
    });
});
