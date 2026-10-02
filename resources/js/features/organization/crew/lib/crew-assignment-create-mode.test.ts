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
    it('hides Save Draft for users without assignment create', () => {
        assert.equal(
            shouldRenderSaveDraftButton({
                canCreate: false,
                crewRowCount: 1,
            }),
            false,
        );
    });

    it('shows Save Draft when the user can create assignments', () => {
        assert.equal(
            shouldRenderSaveDraftButton({
                canCreate: true,
                crewRowCount: 1,
            }),
            true,
        );
    });

    it('shows Save Draft for vacant Planning handoff create users', () => {
        assert.equal(
            shouldRenderSaveDraftButton({
                canCreate: true,
                crewRowCount: 1,
            }),
            true,
        );
    });
});

describe('resolveCreateFooterActions', () => {
    it('does not expose Save as Planned for planning-only users', () => {
        const actions = resolveCreateFooterActions({
            canCreate: false,
            canStart: false,
            crewRowCount: 1,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.deepEqual(actions, {
            showStart: false,
            showDraft: false,
        });
        assert.equal('showPlan' in actions, false);
    });

    it('shows Save Draft for assignment-create users', () => {
        const actions = resolveCreateFooterActions({
            canCreate: true,
            canStart: false,
            crewRowCount: 1,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.equal(actions.showDraft, true);
        assert.equal(actions.showStart, false);
    });

    it('shows Draft and Start on vacant Planning handoff when user can start', () => {
        const actions = resolveCreateFooterActions({
            canCreate: true,
            canStart: true,
            crewRowCount: 1,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.deepEqual(actions, {
            showStart: true,
            showDraft: true,
        });
    });

    it('shows only Save Draft for create-only vacant Planning handoff users', () => {
        const actions = resolveCreateFooterActions({
            canCreate: true,
            canStart: false,
            crewRowCount: 1,
            bulkMode: false,
            planningActiveAssignmentConflict: false,
        });

        assert.deepEqual(actions, {
            showStart: false,
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
        assert.equal(resolveCreateEffectiveEmployeeId(null, null), null);
    });

    it('returns the selected crew row employee on manual create', () => {
        assert.equal(resolveCreateEffectiveEmployeeId(null, 42), 42);
    });

    it('uses the named planning employee when present', () => {
        assert.equal(resolveCreateEffectiveEmployeeId(99, null), 99);
        assert.equal(resolveCreateEffectiveEmployeeId(99, 42), 99);
    });

    it('uses the selected employee on vacant Planning handoff', () => {
        assert.equal(resolveCreateEffectiveEmployeeId(null, 77), 77);
    });
});
