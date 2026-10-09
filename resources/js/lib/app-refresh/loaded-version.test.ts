import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';
import {
    captureLoadedAppVersion,
    getLoadedAppVersion,
    resetLoadedAppVersion,
} from './loaded-version.ts';

describe('captureLoadedAppVersion', () => {
    afterEach(() => {
        resetLoadedAppVersion();
    });

    it('keeps the first captured version when a newer server version arrives', () => {
        assert.equal(captureLoadedAppVersion('deploy-old'), 'deploy-old');
        assert.equal(captureLoadedAppVersion('deploy-new'), 'deploy-old');
        assert.equal(getLoadedAppVersion(), 'deploy-old');
    });
});
