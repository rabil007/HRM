import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { documentTypeSheetKey } from './document-type-sheet-key.ts';

describe('document type sheet key', () => {
    it('remounts when the deep-linked type changes', () => {
        assert.equal(documentTypeSheetKey(12), '12');
        assert.notEqual(documentTypeSheetKey(12), documentTypeSheetKey(34));
    });

    it('uses a stable closed key for missing or invalid ids', () => {
        assert.equal(documentTypeSheetKey(null), 'list');
        assert.equal(documentTypeSheetKey(undefined), 'list');
        assert.equal(documentTypeSheetKey(0), 'list');
    });
});
