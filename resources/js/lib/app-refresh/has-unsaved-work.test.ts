import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    detectUnsavedWork,
    registerUnsavedWorkChecker,
} from './has-unsaved-work.ts';

describe('detectUnsavedWork', () => {
    it('returns false when nothing is dirty', () => {
        assert.equal(detectUnsavedWork({ checkers: [] }), false);
    });

    it('detects the documentElement data attribute', () => {
        assert.equal(
            detectUnsavedWork({
                checkers: [],
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
                checkers: [],
                querySelector: (selector) =>
                    selector === '[data-unsaved-changes="true"]'
                        ? { marked: true }
                        : null,
            }),
            true,
        );
    });

    it('detects registered dirty checkers', () => {
        assert.equal(
            detectUnsavedWork({
                checkers: [() => true],
            }),
            true,
        );
    });

    it('detects beforeunload listeners that prevent default', () => {
        assert.equal(
            detectUnsavedWork({
                checkers: [],
                dispatchBeforeUnload: () => true,
            }),
            true,
        );
    });

    it('unregisters dirty checkers', () => {
        const unregister = registerUnsavedWorkChecker(() => true);

        assert.equal(detectUnsavedWork({}), true);
        unregister();
        assert.equal(
            detectUnsavedWork({
                checkers: [],
            }),
            false,
        );
    });
});
