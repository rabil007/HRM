import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolveAuthUser } from './auth-user.ts';

describe('resolveAuthUser', () => {
    it('returns null when auth or user is missing', () => {
        assert.equal(resolveAuthUser(undefined), null);
        assert.equal(resolveAuthUser(null), null);
        assert.equal(resolveAuthUser({}), null);
        assert.equal(resolveAuthUser({ user: null }), null);
    });

    it('returns the shared auth user when present', () => {
        const user = {
            id: 1,
            name: 'Ada',
            email: 'ada@example.com',
            avatar: '/avatars/ada.png',
            email_verified_at: null,
            created_at: '',
            updated_at: '',
        };

        assert.equal(resolveAuthUser({ user }), user);
    });
});
