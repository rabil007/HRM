import assert from 'node:assert/strict';
import test from 'node:test';
import { generationActionLabel } from './generation-action-label.ts';

test('describes the default missing-document generation action', () => {
    assert.equal(
        generationActionLabel({
            isBusy: false,
            selectedCount: 0,
            missingCount: 328,
        }),
        'Generate 328 missing',
    );
});

test('prioritizes an explicit employee selection', () => {
    assert.equal(
        generationActionLabel({
            isBusy: false,
            selectedCount: 12,
            missingCount: 328,
        }),
        'Generate for 12 selected',
    );
});

test('shows progress while generation is active', () => {
    assert.equal(
        generationActionLabel({
            isBusy: true,
            selectedCount: 12,
            missingCount: 328,
        }),
        'Generating…',
    );
});

test('describes a completed roster when no documents are missing', () => {
    assert.equal(
        generationActionLabel({
            isBusy: false,
            selectedCount: 0,
            missingCount: 0,
        }),
        'All documents generated',
    );
});
