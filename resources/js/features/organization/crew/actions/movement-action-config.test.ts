import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { getMovementActionConfig } from './movement-action-config.ts';

describe('movement action impact previews', () => {
    it('describes transfer vessel impact using implemented behavior', () => {
        const config = getMovementActionConfig('transfer_vessel');

        assert.equal(config.impactPreview, 'full');
        assert.equal(config.impactSeverity, 'high');
        assert.equal(config.submitLabel, 'Confirm Transfer');
        assert.match(
            config.impactDescription.join(' '),
            /P4 On Vessel phase ends/i,
        );
        assert.match(
            config.impactDescription.join(' '),
            /linked destination assignment/i,
        );
    });

    it('describes redeploy linked-assignment behavior', () => {
        const config = getMovementActionConfig('redeploy');

        assert.equal(config.impactPreview, 'full');
        assert.match(
            config.impactDescription.join(' '),
            /linked destination assignment/i,
        );
        assert.match(config.impactDescription.join(' '), /completed/i);
        assert.equal(config.submitLabel, 'Confirm Redeploy');
    });

    it('distinguishes confirm disembarkation from planned sign-off', () => {
        const config = getMovementActionConfig('confirm_disembarkation');

        assert.equal(config.impactPreview, 'full');
        assert.match(
            config.impactDescription.join(' '),
            /Actual disembarkation is recorded/i,
        );
        assert.doesNotMatch(
            config.impactDescription.join(' '),
            /Planned Sign-Off alone does not disembark/i,
        );
    });

    it('describes return home demobilisation transition', () => {
        const config = getMovementActionConfig('travel_home');

        assert.match(
            config.impactDescription.join(' '),
            /Demobilisation standby ends/i,
        );
        assert.match(
            config.impactDescription.join(' '),
            /Home \/ Redeployment stage begins/i,
        );
    });

    it('describes close assignment lifecycle', () => {
        const config = getMovementActionConfig('close_assignment');

        assert.match(config.impactDescription.join(' '), /Completed/i);
        assert.match(
            config.impactDescription.join(' '),
            /Historical movement data remains preserved/i,
        );
        assert.equal(config.submitLabel, 'Close Assignment');
    });

    it('warns that cancel uses destructive presentation', () => {
        const config = getMovementActionConfig('cancel_assignment');

        assert.equal(config.destructive, true);
        assert.equal(config.impactSeverity, 'destructive');
        assert.equal(config.keepOpenLabel, 'Keep Assignment');
        assert.match(config.impactDescription.join(' '), /Cancelled/i);
    });

    it('uses light preview for join vessel and explicit submit label', () => {
        const config = getMovementActionConfig('join_vessel');

        assert.equal(config.impactPreview, 'light');
        assert.equal(config.submitLabel, 'Confirm Join');
    });

    it('uses light preview for start assignment', () => {
        const config = getMovementActionConfig('approve_mobilisation');

        assert.equal(config.impactPreview, 'light');
        assert.equal(config.submitLabel, 'Start Assignment');
    });

    it('does not require impact preview for plan sign-off', () => {
        const config = getMovementActionConfig('plan_signoff');

        assert.equal(config.impactPreview, 'none');
    });
});
