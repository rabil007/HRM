import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    resolveUpdateAvailability,
    shouldAutoOpenUpdateDialog,
} from './version-state.ts';

describe('resolveUpdateAvailability', () => {
    it('keeps the loaded version distinct from a newer server version', () => {
        const result = resolveUpdateAvailability({
            loadedVersion: 'deploy-old',
            serverVersion: 'deploy-new',
            pwaUpdateWaiting: false,
        });

        assert.equal(result.pendingVersion, 'deploy-new');
        assert.equal(result.updateAvailable, true);
    });

    it('does not report an update when versions match', () => {
        const result = resolveUpdateAvailability({
            loadedVersion: 'deploy-1',
            serverVersion: 'deploy-1',
            pwaUpdateWaiting: false,
        });

        assert.equal(result.pendingVersion, null);
        assert.equal(result.updateAvailable, false);
    });

    it('reports an update when a service worker is waiting', () => {
        const result = resolveUpdateAvailability({
            loadedVersion: 'deploy-1',
            serverVersion: 'deploy-1',
            pwaUpdateWaiting: true,
        });

        assert.equal(result.pendingVersion, null);
        assert.equal(result.updateAvailable, true);
    });
});

describe('shouldAutoOpenUpdateDialog', () => {
    it('suppresses auto-open for a dismissed pending version', () => {
        assert.equal(
            shouldAutoOpenUpdateDialog({
                updateAvailable: true,
                pendingVersion: 'deploy-new',
                dismissedVersion: 'deploy-new',
                pwaUpdateWaiting: false,
                force: false,
            }),
            false,
        );
    });

    it('reopens when force is true after Later', () => {
        assert.equal(
            shouldAutoOpenUpdateDialog({
                updateAvailable: true,
                pendingVersion: 'deploy-new',
                dismissedVersion: 'deploy-new',
                pwaUpdateWaiting: false,
                force: true,
            }),
            true,
        );
    });

    it('opens again for a genuinely newer version', () => {
        assert.equal(
            shouldAutoOpenUpdateDialog({
                updateAvailable: true,
                pendingVersion: 'deploy-newer',
                dismissedVersion: 'deploy-new',
                pwaUpdateWaiting: false,
                force: false,
            }),
            true,
        );
    });
});
