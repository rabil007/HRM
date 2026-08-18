import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { needsPrivilegedTwoFactorEnrollment } from './privileged-two-factor.ts';

describe('privileged two-factor enrollment', () => {
    it('is required only when enforcement applies and the user is not enrolled', () => {
        assert.equal(
            needsPrivilegedTwoFactorEnrollment({
                enabled: false,
                required_for_privileged_actions: true,
            }),
            true,
        );
        assert.equal(
            needsPrivilegedTwoFactorEnrollment({
                enabled: true,
                required_for_privileged_actions: true,
            }),
            false,
        );
        assert.equal(
            needsPrivilegedTwoFactorEnrollment({
                enabled: false,
                required_for_privileged_actions: false,
            }),
            false,
        );
        assert.equal(needsPrivilegedTwoFactorEnrollment(null), false);
        assert.equal(needsPrivilegedTwoFactorEnrollment(undefined), false);
    });
});
