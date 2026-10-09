import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { detectUnsavedWork } from './has-unsaved-work.ts';

describe('detectUnsavedWork', () => {
    it('returns false when nothing is dirty', () => {
        assert.equal(detectUnsavedWork({}), false);
    });

    it('detects the documentElement data attribute', () => {
        assert.equal(
            detectUnsavedWork({
                documentElement: {
                    dataset: { unsavedChanges: 'true' },
                },
            }),
            true,
        );
    });

    it('detects a marked element in the DOM', () => {
        assert.equal(
            detectUnsavedWork({
                querySelector: (selector) =>
                    selector === '[data-unsaved-changes="true"]'
                        ? { marked: true }
                        : null,
            }),
            true,
        );
    });

    it('detects beforeunload listeners that prevent default', () => {
        assert.equal(
            detectUnsavedWork({
                dispatchBeforeUnload: () => true,
            }),
            true,
        );
    });
});
