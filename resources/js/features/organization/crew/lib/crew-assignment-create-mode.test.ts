import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    bulkStartButtonLabel,
    isBulkCreateMode,
    resolveCreateEffectiveEmployeeId,
    resolveCreateFooterActions,
    resolveCreateSubmitRoute,
    shouldRenderSaveDraftButton,
    shouldShowSaveDraft,
} from './crew-assignment-create-mode.ts';

describe('isBulkCreateMode', () => {
    it('treats one row as single mode', () => {
        assert.equal(isBulkCreateMode(1), false);
    });

    it('treats two or more rows as bulk mode', () => {
        assert.equal(isBulkCreateMode(2), true);
        assert.equal(isBulkCreateMode(3), true);
    });
});

describe('shouldShowSaveDraft', () => {
    it('allows draft only for a single crew row', () => {
        assert.equal(shouldShowSaveDraft(1), true);
        assert.equal(shouldShowSaveDraft(2), false);
    });
});

describe('shouldRenderSaveDraftButton', () => {
    it('hides Save Draft for plan-only users', () => {
        assert.equal(
            shouldRenderSaveDraftButton({
                canCreate: false,
                crewRowCount: 1,
                fromPlanning: false,
            }),
            false,
        );
    });

    it('shows Save Draft when the user can create assignments', () => {
        assert.equal(
            shouldRenderSaveDraftButton({
                canCreate: true,
                crewRowCount: 1,
                fromPlanning: false,
            }),
            true,
        );
    });

    it('hides Save Draft when starting from a planning handoff', () => {
        assert.equal(
            shouldRenderSaveDraftButton({
                canCreate: true,
                crewRowCount: 1,
                fromPlanning: true,
            }),
            false,
        );
    });
});

describe('resolveCreateFooterActions', () => {
    it('shows only Save as Planned for planning-only users', () => {
        const actions = resolveCreateFooterActions({
            canCreate: false,
            canPlan: true,
            canStart: false,
            crewRowCount: 1,
            fromPlanning: false,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.deepEqual(actions, {
            showStart: false,
            showPlan: true,
            showDraft: false,
        });
    });

    it('shows Save Draft for assignment-create users', () => {
        const actions = resolveCreateFooterActions({
            canCreate: true,
            canPlan: false,
            canStart: false,
            crewRowCount: 1,
            fromPlanning: false,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.equal(actions.showDraft, true);
        assert.equal(actions.showPlan, false);
        assert.equal(actions.showStart, false);
    });

    it('shows Start when the user has start permission', () => {
        const actions = resolveCreateFooterActions({
            canCreate: true,
            canPlan: true,
            canStart: true,
            crewRowCount: 1,
            fromPlanning: false,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.deepEqual(actions, {
            showStart: true,
            showPlan: true,
            showDraft: true,
        });
    });
});

describe('resolveCreateSubmitRoute', () => {
    it('routes one row to the single store endpoint', () => {
        assert.equal(resolveCreateSubmitRoute(1), 'single');
    });

    it('routes two or more rows to the bulk store endpoint', () => {
        assert.equal(resolveCreateSubmitRoute(2), 'bulk');
        assert.equal(resolveCreateSubmitRoute(4), 'bulk');
    });
});

describe('bulkStartButtonLabel', () => {
    it('labels the bulk start action with the ready count', () => {
        assert.equal(bulkStartButtonLabel(1), 'Start 1 Assignment');
        assert.equal(bulkStartButtonLabel(2), 'Start 2 Assignments');
    });
});

describe('resolveCreateEffectiveEmployeeId', () => {
    it('returns null before an employee is selected on manual create', () => {
        assert.equal(resolveCreateEffectiveEmployeeId(false, null, null), null);
    });

    it('returns the selected crew row employee immediately', () => {
        assert.equal(resolveCreateEffectiveEmployeeId(false, null, 42), 42);
    });

    it('uses the planning employee when starting from Crew Planning', () => {
        assert.equal(resolveCreateEffectiveEmployeeId(true, 99, null), 99);
    });
});
