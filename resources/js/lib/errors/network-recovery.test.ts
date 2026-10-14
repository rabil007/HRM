import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { after, before, beforeEach, describe, it } from 'node:test';

const notifications: Array<{ type: string; message: string }> = [];
(globalThis as typeof globalThis & { __omsNetworkTestNotifications: typeof notifications }).__omsNetworkTestNotifications = notifications;

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier === '@/lib/toast') {
            const source = `export const toast = {
                error: (message) => globalThis.__omsNetworkTestNotifications.push({ type: 'error', message }),
                warning: (message) => globalThis.__omsNetworkTestNotifications.push({ type: 'warning', message }),
                success: (message) => globalThis.__omsNetworkTestNotifications.push({ type: 'success', message }),
            };`;

            return {
                url: `data:text/javascript,${encodeURIComponent(source)}`,
                shortCircuit: true,
            };
        }

        return nextResolve(specifier, context);
    },
});

const {
    notifyNetworkFailure,
    notifyOffline,
    notifyOnline,
    getNetworkRecoveryStateForTests,
    resetNetworkRecoveryStateForTests,
} = await import('./network-recovery.ts');

const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
const originalNow = Date.now;
let now = 10_000;
let online = false;

before(() => {
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        get: () => ({ onLine: online }),
    });
    Date.now = () => now;
});

after(() => {
    Date.now = originalNow;
    if (originalNavigator) {
        Object.defineProperty(globalThis, 'navigator', originalNavigator);
    } else {
        delete (globalThis as { navigator?: Navigator }).navigator;
    }
    delete (globalThis as typeof globalThis & { __omsNetworkTestNotifications?: typeof notifications }).__omsNetworkTestNotifications;
});

beforeEach(() => {
    now = 10_000;
    online = false;
    notifications.length = 0;
    resetNetworkRecoveryStateForTests();
});

describe('network recovery toasts', () => {
    it('shows the first offline network failure immediately, without double debounce', () => {
        notifyNetworkFailure();
        assert.deepEqual(notifications.map((event) => event.type), ['warning']);
        assert.equal(getNetworkRecoveryStateForTests().offlineNotified, true);

        notifyNetworkFailure();
        notifyOffline();
        assert.equal(notifications.length, 1);
    });

    it('uses the server-unreachable message when the browser reports online', () => {
        online = true;
        notifyNetworkFailure();
        assert.deepEqual(notifications.map((event) => event.type), ['error']);
        notifyNetworkFailure();
        assert.equal(notifications.length, 1);
    });

    it('only reports reconnection after a previously detected offline transition', () => {
        online = true;
        notifyOnline();
        assert.equal(notifications.length, 0);

        online = false;
        notifyNetworkFailure();
        now += 5_000;
        online = true;
        notifyOnline();
        assert.deepEqual(notifications.map((event) => event.type), ['warning', 'success']);
    });
});
