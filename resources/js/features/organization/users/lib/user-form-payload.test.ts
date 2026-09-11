import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { UserFormData } from '../types.ts';
import { buildUserFormPayload } from './user-form-payload.ts';

function formData(overrides: Partial<UserFormData> = {}): UserFormData {
    return {
        name: 'Jane Doe',
        email: 'jane@example.com',
        avatar: null,
        use_employee_avatar: false,
        employee_id: '',
        role_id: '',
        status: 'active',
        ...overrides,
    };
}

describe('buildUserFormPayload', () => {
    it('omits a null avatar and spoofs put so Laravel can parse multipart updates', () => {
        const payload = buildUserFormPayload(formData(), true);

        assert.equal(payload.name, 'Jane Doe');
        assert.equal(payload.email, 'jane@example.com');
        assert.equal(payload._method, 'put');
        assert.equal('avatar' in payload, false);
    });

    it('keeps an uploaded avatar and does not spoof create posts', () => {
        const avatar = new File(['photo'], 'avatar.png', { type: 'image/png' });
        const payload = buildUserFormPayload(formData({ avatar }), false);

        assert.equal(payload.avatar, avatar);
        assert.equal('_method' in payload, false);
    });
});
