import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { bulkDocumentsPollOnlyProps } from './bulk-documents-poll-props.ts';

describe('bulkDocumentsPollOnlyProps', () => {
    it('requests nested signature_payload props when embedded in requests', () => {
        const props = bulkDocumentsPollOnlyProps(true);

        assert.ok(props.includes('signature_payload.signature_requests'));
        assert.ok(
            props.includes('signature_payload.latest_signature_repair_run'),
        );
        assert.ok(props.includes('pagination'));
        assert.ok(!props.includes('signature_requests'));
        assert.ok(!props.includes('employees'));
    });

    it('requests top-level bulk props on the legacy bulk page', () => {
        const props = bulkDocumentsPollOnlyProps(false);

        assert.ok(props.includes('signature_requests'));
        assert.ok(props.includes('employees'));
        assert.ok(!props.includes('signature_payload.signature_requests'));
    });
});
