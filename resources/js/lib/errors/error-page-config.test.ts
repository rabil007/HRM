import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    getErrorPageConfig,
    isErrorPageStatus,
    supportedErrorPageStatuses,
} from './error-page-config.ts';

describe('error-page-config', () => {
    it('exposes all supported HTTP statuses', () => {
        assert.deepEqual(
            supportedErrorPageStatuses.sort(),
            [400, 401, 403, 404, 419, 429, 500, 503],
        );
    });

    it('returns stable copy for each status', () => {
        for (const status of supportedErrorPageStatuses) {
            const config = getErrorPageConfig(status);

            assert.equal(config.status, status);
            assert.ok(config.title.length > 0);
            assert.ok(config.heading.length > 0);
            assert.ok(config.description.length > 0);
            assert.ok(config.actions.length > 0);
            assert.ok(config.statusLabel.includes(String(status)));
        }
    });

    it('marks retry-eligible server and rate-limit pages', () => {
        assert.equal(getErrorPageConfig(429).retryEligible, true);
        assert.equal(getErrorPageConfig(500).retryEligible, true);
        assert.equal(getErrorPageConfig(503).retryEligible, true);
        assert.equal(getErrorPageConfig(403).retryEligible, false);
    });

    it('validates status membership', () => {
        assert.equal(isErrorPageStatus(404), true);
        assert.equal(isErrorPageStatus(418), false);
    });
});
