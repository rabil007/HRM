import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { activateWaitingServiceWorker } from './service-worker-activation.ts';

describe('activateWaitingServiceWorker', () => {
    it('returns none when no waiting worker exists', async () => {
        const result = await activateWaitingServiceWorker({
            registration: { waiting: null },
        });

        assert.equal(result, 'none');
    });

    it('returns activated when controllerchange fires', async () => {
        let listener: (() => void) | null = null;
        const waiting = {
            postMessage: () => {
                listener?.();
            },
        } as unknown as ServiceWorker;

        const result = await activateWaitingServiceWorker({
            registration: { waiting },
            timeoutMs: 50,
            postMessage: (worker, message) => {
                assert.deepEqual(message, { type: 'SKIP_WAITING' });
                (
                    worker as { postMessage: (value: unknown) => void }
                ).postMessage(message);
            },
            addControllerChangeListener: (next) => {
                listener = next;

                return () => {
                    listener = null;
                };
            },
        });

        assert.equal(result, 'activated');
    });

    it('returns timeout without claiming activation', async () => {
        const waiting = {
            postMessage: () => undefined,
        } as unknown as ServiceWorker;

        const result = await activateWaitingServiceWorker({
            registration: { waiting },
            timeoutMs: 10,
            addControllerChangeListener: () => () => undefined,
        });

        assert.equal(result, 'timeout');
    });
});
