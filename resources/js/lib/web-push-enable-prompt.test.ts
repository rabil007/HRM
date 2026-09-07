import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    isWebPushEnablePromptDismissed,
    shouldShowWebPushEnablePrompt,
    webPushEnablePromptStorageKey,
    WEB_PUSH_ENABLE_PROMPT_DISMISS_MS,
} from './web-push-enable-prompt.ts';

const now = 1_700_000_000_000;

function eligible(
    overrides: Partial<
        Parameters<typeof shouldShowWebPushEnablePrompt>[0]
    > = {},
) {
    return shouldShowWebPushEnablePrompt({
        userId: 12,
        serverConfigured: true,
        browserSupportsWebPush: true,
        status: 'not_enabled',
        notificationPermission: 'default',
        isDismissed: false,
        ...overrides,
    });
}

describe('web push enable prompt eligibility', () => {
    it('shows when not enabled and native permission is default', () => {
        assert.equal(eligible(), true);
    });

    it('hides when notifications are already enabled', () => {
        assert.equal(eligible({ status: 'enabled' }), false);
    });

    it('hides when the browser permission is denied', () => {
        assert.equal(eligible({ status: 'denied' }), false);
        assert.equal(
            eligible({
                status: 'not_enabled',
                notificationPermission: 'denied',
            }),
            false,
        );
    });

    it('hides when web push is unsupported', () => {
        assert.equal(eligible({ status: 'unsupported' }), false);
        assert.equal(eligible({ browserSupportsWebPush: false }), false);
        assert.equal(eligible({ serverConfigured: false }), false);
    });

    it('hides while permission or subscription is in progress', () => {
        const busy = ['requesting_permission', 'subscribing', 'error'] as const;

        for (const status of busy) {
            assert.equal(eligible({ status }), false);
        }
    });

    it('hides when permission is granted but the app subscription is off', () => {
        assert.equal(
            eligible({
                status: 'not_enabled',
                notificationPermission: 'granted',
            }),
            false,
        );
    });

    it('hides after a recent Not now dismissal', () => {
        assert.equal(eligible({ isDismissed: true }), false);
        assert.equal(
            isWebPushEnablePromptDismissed(
                now + WEB_PUSH_ENABLE_PROMPT_DISMISS_MS,
                now,
            ),
            true,
        );
    });

    it('shows again after the 7-day dismissal expires', () => {
        assert.equal(isWebPushEnablePromptDismissed(now - 1, now), false);
        assert.equal(eligible({ isDismissed: false }), true);
    });

    it('hides when the user is not authenticated', () => {
        assert.equal(eligible({ userId: null }), false);
    });

    it('uses a user-specific dismissal storage key', () => {
        assert.equal(
            webPushEnablePromptStorageKey(12),
            'oms-hrm:web-push-prompt-dismissed-until:12',
        );
        assert.equal(
            webPushEnablePromptStorageKey(99),
            'oms-hrm:web-push-prompt-dismissed-until:99',
        );
        assert.notEqual(
            webPushEnablePromptStorageKey(12),
            webPushEnablePromptStorageKey(99),
        );
    });
});
