import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { inertiaPageLayoutKind } from './inertia-page-layout.ts';

describe('inertiaPageLayoutKind', () => {
    it('keeps public document-action pages outside the admin layout', () => {
        assert.equal(
            inertiaPageLayoutKind('document-action/index'),
            'none',
        );
    });

    it('keeps other public surfaces outside the admin layout', () => {
        assert.equal(inertiaPageLayoutKind('welcome'), 'none');
        assert.equal(inertiaPageLayoutKind('shared/show'), 'none');
        assert.equal(inertiaPageLayoutKind('public/announcements/show'), 'none');
        assert.equal(inertiaPageLayoutKind('esign/show'), 'none');
        assert.equal(inertiaPageLayoutKind('errors/404'), 'none');
    });

    it('uses auth, settings, and app layouts for internal pages', () => {
        assert.equal(inertiaPageLayoutKind('auth/login'), 'auth');
        assert.equal(inertiaPageLayoutKind('settings/profile'), 'settings');
        assert.equal(inertiaPageLayoutKind('dashboard'), 'app');
        assert.equal(
            inertiaPageLayoutKind('organization/documents/show'),
            'app',
        );
    });
});
