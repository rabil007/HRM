import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    REQUIREMENT_HOLD_FEATURE_ENABLED,
    isRequirementHoldActionVisible,
    isRequirementResumeActionVisible,
} from './requirement-hold-feature.ts';

describe('requirement hold feature flag', () => {
    it('hides Put on hold while the feature is disabled', () => {
        assert.equal(REQUIREMENT_HOLD_FEATURE_ENABLED, false);
        assert.equal(isRequirementHoldActionVisible(true), false);
        assert.equal(isRequirementHoldActionVisible(false), false);
    });

    it('keeps Resume only for existing On Hold requirements when Hold is hidden', () => {
        assert.equal(
            isRequirementResumeActionVisible({
                canResume: true,
                status: 'on_hold',
            }),
            true,
        );
        assert.equal(
            isRequirementResumeActionVisible({
                canResume: true,
                status: 'open',
            }),
            false,
        );
        assert.equal(
            isRequirementResumeActionVisible({
                canResume: false,
                status: 'on_hold',
            }),
            false,
        );
    });
});
