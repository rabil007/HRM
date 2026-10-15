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
        assert.deepEqual(resolveSingleSubmitRequest('pre_arrival'), {
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
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.pre_arrival.length > 0,
        );
        assert.ok(CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.draft.length > 0);
    });

    it('describes Already Arrived using modern P1A Pre-Joining Standby wording', () => {
        assert.match(
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.already_arrived,
            /Pre-Joining Standby \(P1A\)/,
        );
        assert.doesNotMatch(
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.already_arrived,
            /P2A/,
        );
        assert.match(
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.pre_arrival,
            /P0 Pre-Arrival/,
        );
        assert.doesNotMatch(
            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.pre_arrival,
            /Pre-Mobilisation/,
        );
    });
});
