import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    currentStateLabel,
    otherValidMovementActions,
} from './crew-operational-state.ts';

const permissions = {
    perform_movement: true,
    cancel: true,
};

describe('crew operational state helpers', () => {
    it('labels the current phase consistently', () => {
        assert.equal(
            currentStateLabel({
                current_phase: { code: 'p2a', label: 'Join Standby' },
                status: 'active',
                status_label: 'Active',
            }),
            'P2A · Join Standby',
        );
    });

    it('lists other valid P2A actions without duplicating the recommended action', () => {
        assert.deepEqual(
            otherValidMovementActions(
                ['join_vessel', 'send_to_training'],
                {
                    type: 'movement',
                    action: 'join_vessel',
                    label: 'Join Vessel',
                    reason: 'Ready to board.',
                    href: null,
                    anyway_action: null,
                    anyway_label: null,
                },
                permissions,
            ),
            ['send_to_training'],
        );
    });

    it('lists other valid P4 actions when confirm disembarkation is recommended', () => {
        const other = otherValidMovementActions(
            ['confirm_disembarkation', 'plan_signoff', 'transfer_vessel'],
            {
                type: 'movement',
                action: 'confirm_disembarkation',
                label: 'Confirm Disembarkation',
                reason: 'Actual leave.',
                href: null,
                anyway_action: null,
                anyway_label: null,
            },
            permissions,
        );

        assert.deepEqual(other, ['plan_signoff', 'transfer_vessel']);
    });

    it('lists return home and redeploy for P5 recommendations', () => {
        assert.deepEqual(
            otherValidMovementActions(
                ['travel_home', 'redeploy'],
                {
                    type: 'movement',
                    action: 'travel_home',
                    label: 'Return Home',
                    reason: 'Awaiting onward movement.',
                    href: null,
                    anyway_action: null,
                    anyway_label: null,
                },
                permissions,
            ),
            ['redeploy'],
        );
    });

    it('omits unauthorized actions', () => {
        assert.deepEqual(
            otherValidMovementActions(
                ['join_vessel', 'send_to_training', 'cancel_assignment'],
                null,
                { perform_movement: false, cancel: false },
            ),
            [],
        );
    });

    it('lists complete training as the other valid P2B action', () => {
        assert.deepEqual(
            otherValidMovementActions(
                ['complete_training'],
                {
                    type: 'movement',
                    action: 'complete_training',
                    label: 'Complete Training',
                    reason: 'Training finished.',
                    href: null,
                    anyway_action: null,
                    anyway_label: null,
                },
                permissions,
            ),
            [],
        );
    });

    it('lists close and redeploy for P6 recommendations', () => {
        assert.deepEqual(
            otherValidMovementActions(
                ['close_assignment', 'redeploy'],
                {
                    type: 'movement',
                    action: 'close_assignment',
                    label: 'Close Assignment',
                    reason: 'Cycle complete.',
                    href: null,
                    anyway_action: null,
                    anyway_label: null,
                },
                permissions,
            ),
            ['redeploy'],
        );
    });
});
