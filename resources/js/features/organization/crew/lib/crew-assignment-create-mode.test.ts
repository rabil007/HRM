import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    bulkStartButtonLabel,
    isBulkCreateMode,
    resolveCreateSubmitRoute,
    shouldShowSaveDraft,
} from './crew-assignment-create-mode.ts';

describe('isBulkCreateMode', () => {
    it('treats one row as single mode', () => {
        assert.equal(isBulkCreateMode(1), false);
    });

    it('treats two or more rows as bulk mode', () => {
        assert.equal(isBulkCreateMode(2), true);
        assert.equal(isBulkCreateMode(3), true);
    });
});

describe('shouldShowSaveDraft', () => {
    it('allows draft only for a single crew row', () => {
        assert.equal(shouldShowSaveDraft(1), true);
        assert.equal(shouldShowSaveDraft(2), false);
    });
});

describe('resolveCreateSubmitRoute', () => {
    it('routes one row to the single store endpoint', () => {
        assert.equal(resolveCreateSubmitRoute(1), 'single');
    });

    it('routes two or more rows to the bulk store endpoint', () => {
        assert.equal(resolveCreateSubmitRoute(2), 'bulk');
        assert.equal(resolveCreateSubmitRoute(4), 'bulk');
    });
});

describe('bulkStartButtonLabel', () => {
    it('labels the bulk start action with the ready count', () => {
        assert.equal(bulkStartButtonLabel(1), 'Start 1 Assignment');
        assert.equal(bulkStartButtonLabel(2), 'Start 2 Assignments');
    });
});
