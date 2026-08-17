import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { detectStandalonePwa } from './use-is-standalone-pwa.ts';

describe('detectStandalonePwa', () => {
    it('returns false when browser APIs are unavailable', () => {
        assert.equal(detectStandalonePwa(undefined), false);
        assert.equal(detectStandalonePwa({}), false);
    });

    it('detects the standalone display mode', () => {
        assert.equal(
            detectStandalonePwa({
                matchMedia: (query) => ({
                    matches: query === '(display-mode: standalone)',
                }),
            }),
            true,
        );
    });

    it('detects iOS standalone mode', () => {
        assert.equal(
            detectStandalonePwa({
                matchMedia: () => ({ matches: false }),
                navigator: { standalone: true },
            }),
            true,
        );
    });

    it('stays hidden in a normal browser tab', () => {
        assert.equal(
            detectStandalonePwa({
                matchMedia: () => ({ matches: false }),
                navigator: { standalone: false },
            }),
            false,
        );
    });

    it('does not throw when matchMedia fails', () => {
        assert.equal(
            detectStandalonePwa({
                matchMedia: () => {
                    throw new Error('matchMedia unavailable');
                },
                navigator: { standalone: false },
            }),
            false,
        );
    });

    it('still detects iOS standalone when matchMedia fails', () => {
        assert.equal(
            detectStandalonePwa({
                matchMedia: () => {
                    throw new Error('matchMedia unavailable');
                },
                navigator: { standalone: true },
            }),
            true,
        );
    });
});
