import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { getMovementActionConfig } from './movement-action-config.ts';

describe('movement action impact previews', () => {
    it('describes transfer vessel impact using implemented behavior', () => {
        const config = getMovementActionConfig('transfer_vessel');

        assert.equal(config.impactTitle, 'What will happen');
        assert.ok(Array.isArray(config.impactDescription));
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

        assert.equal(config.impactTitle, 'What will happen');
        assert.match(
            config.impactDescription.join(' '),
            /linked destination assignment/i,
        );
        assert.match(config.impactDescription.join(' '), /completed/i);
    });

    it('distinguishes confirm disembarkation from planned sign-off', () => {
        const config = getMovementActionConfig('confirm_disembarkation');

        assert.equal(config.impactTitle, 'What will happen');
        assert.match(
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
            /no other active assignment exists/i,
        );
    });

    it('warns that cancel preserves historical movement data', () => {
        const config = getMovementActionConfig('cancel_assignment');

        assert.equal(config.destructive, true);
        assert.match(config.impactDescription.join(' '), /remain preserved/i);
        assert.match(
            config.impactDescription.join(' '),
            /cannot be cancelled directly/i,
        );
    });
});
