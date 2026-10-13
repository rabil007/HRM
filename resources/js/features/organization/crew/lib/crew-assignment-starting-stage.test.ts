import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    availableStartingStages,
    resolveStartingStageSubmitLabel,
    startingStageRequiresActualArrival,
    startingStageToSubmissionIntent,
} from './crew-assignment-starting-stage.ts';

describe('availableStartingStages', () => {
    it('returns no stages without create permission', () => {
        assert.deepEqual(
            availableStartingStages({ canCreate: false, canStart: true }),
            [],
        );
    });

    it('returns draft only for create-only users', () => {
        assert.deepEqual(
            availableStartingStages({ canCreate: true, canStart: false }),
            ['draft'],
        );
    });

    it('returns all three stages when the user can start movements', () => {
        assert.deepEqual(
            availableStartingStages({ canCreate: true, canStart: true }),
            ['draft', 'pre_mobilisation', 'already_arrived'],
        );
    });
});

describe('startingStageToSubmissionIntent', () => {
    it('maps draft to draft intent', () => {
        assert.equal(startingStageToSubmissionIntent('draft'), 'draft');
    });

    it('maps active stages to start intent', () => {
        assert.equal(
            startingStageToSubmissionIntent('pre_mobilisation'),
            'start',
        );
        assert.equal(
            startingStageToSubmissionIntent('already_arrived'),
            'start',
        );
    });
});

describe('resolveStartingStageSubmitLabel', () => {
    it('labels draft and active starts distinctly', () => {
        assert.equal(resolveStartingStageSubmitLabel('draft'), 'Save as Draft');
        assert.equal(
            resolveStartingStageSubmitLabel('pre_mobilisation'),
            'Create & Start',
        );
        assert.equal(
            resolveStartingStageSubmitLabel('already_arrived'),
            'Create & Start',
        );
        assert.equal(resolveStartingStageSubmitLabel(null), 'Continue');
    });
});

describe('startingStageRequiresActualArrival', () => {
    it('requires arrival only for already arrived', () => {
        assert.equal(
            startingStageRequiresActualArrival('already_arrived'),
            true,
        );
        assert.equal(
            startingStageRequiresActualArrival('pre_mobilisation'),
            false,
        );
        assert.equal(startingStageRequiresActualArrival(null), false);
    });
});
