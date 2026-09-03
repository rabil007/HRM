import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { bulkDocumentsPollOnlyProps } from './bulk-documents-poll-props.ts';

describe('bulkDocumentsPollOnlyProps', () => {
    it('requests top-level generate and activity props', () => {
        const props = bulkDocumentsPollOnlyProps();

        assert.ok(props.includes('employees'));
        assert.ok(props.includes('activity'));
        assert.ok(props.includes('latest_run'));
        assert.ok(!props.includes('signature_requests'));
        assert.ok(!props.includes('signature_payload.signature_requests'));
    });
});
