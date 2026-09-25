import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    currentStateLabel,
    otherValidMovementActions,
    resolveOperatorNextStepButtons,
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

describe('resolveOperatorNextStepButtons', () => {
    it('shows Start Assignment once when draft has no readiness issue', () => {
        const buttons = resolveOperatorNextStepButtons({
            recommended: {
                type: 'movement',
                action: 'approve_mobilisation',
                label: 'Start Assignment',
                reason: 'Assignment is in draft.',
                href: null,
                anyway_action: null,
                anyway_label: null,
            },
            availableActions: ['approve_mobilisation', 'cancel_assignment'],
            permissions,
            canViewDocuments: true,
            canViewPlanning: false,
        });

        const startLabels = buttons.filter((button) =>
            button.label.includes('Start Assignment'),
        );

        assert.equal(startLabels.length, 1);
        assert.equal(startLabels[0]?.kind, 'movement');
        assert.equal(startLabels[0]?.action, 'approve_mobilisation');
        assert.ok(buttons.some((button) => button.kind === 'cancel'));
        assert.equal(
            buttons.some((button) => button.label.includes('Anyway')),
            false,
        );
    });

    it('keeps advisory readiness guidance without a second Start Anyway button', () => {
        const buttons = resolveOperatorNextStepButtons({
            recommended: {
                type: 'readiness',
                action: null,
                label: 'Resolve readiness issues before mobilisation',
                reason: 'One mobilisation requirement needs attention. This is guidance only and does not block movement.',
                href: '/organization/documents/employees/1',
                anyway_action: null,
                anyway_label: null,
            },
            availableActions: ['approve_mobilisation', 'cancel_assignment'],
            permissions,
            canViewDocuments: true,
            canViewPlanning: false,
        });

        assert.ok(buttons.some((button) => button.label === 'Open Documents'));
        assert.equal(
            buttons.filter((button) => button.label === 'Start Assignment')
                .length,
            1,
        );
        assert.equal(
            buttons.some((button) =>
                button.label.includes('Start Assignment Anyway'),
            ),
            false,
        );
        assert.ok(buttons.some((button) => button.kind === 'cancel'));
        assert.ok(
            buttons.some(
                (button) =>
                    button.action === 'approve_mobilisation' &&
                    button.kind === 'movement',
            ),
        );
    });

    it('does not disable Start Assignment for advisory readiness', () => {
        const buttons = resolveOperatorNextStepButtons({
            recommended: {
                type: 'readiness',
                action: null,
                label: 'Resolve readiness issues before mobilisation',
                reason: 'Guidance only.',
                href: '/docs',
                anyway_action: null,
                anyway_label: null,
            },
            availableActions: ['approve_mobilisation'],
            permissions: { perform_movement: true, cancel: false },
            canViewDocuments: true,
            canViewPlanning: false,
        });

        assert.ok(
            buttons.some(
                (button) =>
                    button.action === 'approve_mobilisation' &&
                    button.kind === 'movement',
            ),
        );
    });

    it('preserves an authorized override button when anyway_action is present', () => {
        const buttons = resolveOperatorNextStepButtons({
            recommended: {
                type: 'readiness',
                action: null,
                label: 'Resolve readiness issues before mobilisation',
                reason: 'Blocked until resolved.',
                href: '/docs',
                anyway_action: 'approve_mobilisation',
                anyway_label: 'Start Assignment Anyway',
            },
            availableActions: ['approve_mobilisation', 'cancel_assignment'],
            permissions,
            canViewDocuments: true,
            canViewPlanning: false,
        });

        assert.equal(
            buttons.filter((button) =>
                button.label.includes('Start Assignment'),
            ).length,
            1,
        );
        assert.equal(
            buttons.find((button) => button.kind === 'anyway')?.label,
            'Start Assignment Anyway',
        );
        assert.equal(
            buttons.some(
                (button) =>
                    button.kind === 'movement' &&
                    button.action === 'approve_mobilisation',
            ),
            false,
        );
    });

    it('hides override when the user cannot perform movement', () => {
        const buttons = resolveOperatorNextStepButtons({
            recommended: {
                type: 'readiness',
                action: null,
                label: 'Resolve readiness issues before mobilisation',
                reason: 'Blocked.',
                href: '/docs',
                anyway_action: 'approve_mobilisation',
                anyway_label: 'Start Assignment Anyway',
            },
            availableActions: ['approve_mobilisation', 'cancel_assignment'],
            permissions: { perform_movement: false, cancel: true },
            canViewDocuments: true,
            canViewPlanning: false,
        });

        assert.equal(
            buttons.some((button) => button.kind === 'anyway'),
            false,
        );
        assert.ok(buttons.some((button) => button.kind === 'cancel'));
    });
});
