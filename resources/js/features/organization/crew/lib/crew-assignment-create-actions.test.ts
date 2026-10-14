import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS,
    resolveSingleSubmitRequest,
} from './crew-assignment-create-actions.ts';

describe('resolveSingleSubmitRequest', () => {
    it('maps each footer action to submission intent and already arrived flag', () => {
        assert.deepEqual(resolveSingleSubmitRequest('draft'), {
            submissionIntent: 'draft',
            alreadyArrived: false,
        });
        assert.deepEqual(resolveSingleSubmitRequest('pre_mobilisation'), {
            submissionIntent: 'start',
            alreadyArrived: false,
        });
        assert.deepEqual(resolveSingleSubmitRequest('already_arrived'), {
            submissionIntent: 'start',
            alreadyArrived: true,
        });
    });
});

describe('CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS', () => {
    it('defines tooltip copy for every action', () => {
        assert.ok(
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.already_arrived.length > 0,
        );
        assert.ok(
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.pre_mobilisation.length > 0,
        );
        assert.ok(CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.draft.length > 0);
    });
});
