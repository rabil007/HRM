import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    audiencesForRequest,
    buildSendConfirmSnapshot,
    canStartSendConfirmPreview,
    channelsForSendConfirmDialog,
    recipientCountForSendConfirmDialog,
    resolveSendConfirmPreviewResponse,
    shouldApplyPreviewRequestResult,
    shouldOpenSendConfirmDialog,
    shouldSubmitSendNow,
} from './send-announcement-confirm-flow.ts';

const employees = Array.from({ length: 286 }, (_, index) => ({
    id: index + 1,
}));

describe('audiencesForRequest', () => {
    it('normalizes all employees selection', () => {
        assert.deepEqual(
            audiencesForRequest(
                [{ type: 'all_employees', id: null }],
                employees,
            ),
            [{ type: 'all_employees', id: null }],
        );
    });

    it('collapses a full manual employee selection into all employees', () => {
        assert.deepEqual(
            audiencesForRequest(
                employees.map((employee) => ({
                    type: 'employee',
                    id: employee.id,
                })),
                employees,
            ),
            [{ type: 'all_employees', id: null }],
        );
    });
});

describe('send confirm preview flow', () => {
    it('uses the fresh preview count instead of a stale sidebar count', () => {
        const staleSidebarCount = 25;
        const freshPreview = { selected_employees: 286 };
        const channels = ['in_app', 'email'];

        const result = resolveSendConfirmPreviewResponse(
            2,
            2,
            freshPreview,
            channels,
            false,
        );

        assert.equal(staleSidebarCount, 25);
        assert.equal(shouldOpenSendConfirmDialog(result), true);
        assert.equal(result.kind, 'success');

        if (result.kind === 'success') {
            assert.equal(result.snapshot.recipientCount, 286);
            assert.notEqual(result.snapshot.recipientCount, staleSidebarCount);
            assert.equal(
                recipientCountForSendConfirmDialog(result.snapshot),
                286,
            );
        }
    });

    it('snapshots current channels for the confirmation dialog', () => {
        const result = resolveSendConfirmPreviewResponse(
            1,
            1,
            { selected_employees: 12 },
            ['in_app', 'email', 'whatsapp'],
            false,
        );

        assert.equal(result.kind, 'success');

        if (result.kind === 'success') {
            assert.deepEqual(result.snapshot.channels, [
                'in_app',
                'email',
                'whatsapp',
            ]);
            assert.deepEqual(channelsForSendConfirmDialog(result.snapshot), [
                'in_app',
                'email',
                'whatsapp',
            ]);
        }
    });

    it('does not open confirmation on preview failure', () => {
        const result = resolveSendConfirmPreviewResponse(
            1,
            1,
            null,
            ['in_app'],
            true,
        );

        assert.equal(result.kind, 'failure');
        assert.equal(shouldOpenSendConfirmDialog(result), false);
        assert.equal(recipientCountForSendConfirmDialog(null), null);
    });

    it('ignores stale preview responses from earlier requests', () => {
        const result = resolveSendConfirmPreviewResponse(
            1,
            2,
            { selected_employees: 25 },
            ['in_app'],
            false,
        );

        assert.equal(result.kind, 'stale');
        assert.equal(shouldOpenSendConfirmDialog(result), false);
    });

    it('blocks rapid Send Now clicks while preview or dialog is active', () => {
        assert.equal(
            canStartSendConfirmPreview({
                loading: false,
                dialogOpen: false,
                formProcessing: false,
            }),
            true,
        );
        assert.equal(
            canStartSendConfirmPreview({
                loading: true,
                dialogOpen: false,
                formProcessing: false,
            }),
            false,
        );
        assert.equal(
            canStartSendConfirmPreview({
                loading: false,
                dialogOpen: true,
                formProcessing: false,
            }),
            false,
        );
    });

    it('allows only one final send submission', () => {
        assert.equal(
            shouldSubmitSendNow({
                formProcessing: false,
                alreadySubmitting: false,
            }),
            true,
        );
        assert.equal(
            shouldSubmitSendNow({
                formProcessing: true,
                alreadySubmitting: false,
            }),
            false,
        );
        assert.equal(
            shouldSubmitSendNow({
                formProcessing: false,
                alreadySubmitting: true,
            }),
            false,
        );
    });

    it('keeps debounced sidebar preview updates independent from send confirm', () => {
        const debouncedPreviewRequestId = 4;
        const sendConfirmPreviewRequestId = 2;

        assert.equal(
            shouldApplyPreviewRequestResult(4, debouncedPreviewRequestId),
            true,
        );
        assert.equal(
            shouldApplyPreviewRequestResult(2, sendConfirmPreviewRequestId),
            true,
        );
        assert.equal(
            shouldApplyPreviewRequestResult(2, debouncedPreviewRequestId),
            false,
        );
    });

    it('builds an immutable snapshot for confirm rendering', () => {
        const channels = ['email'];
        const snapshot = buildSendConfirmSnapshot(
            { selected_employees: 8 },
            channels,
        );

        channels.push('whatsapp');

        assert.deepEqual(snapshot.channels, ['email']);
        assert.equal(snapshot.recipientCount, 8);
    });
});
