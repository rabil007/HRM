import assert from 'node:assert/strict';
import test from 'node:test';
import {
    emptyDocumentAiBatch,
    mapBatchResponse,
    resetFailedItem,
} from './document-ai-batch-state.ts';
test('maps successful and failed files independently and ignores stale drafts', () => {
    const state = mapBatchResponse(
        {
            id: 1,
            status: 'completed_with_errors',
            items: [
                {
                    id: 2,
                    draft_id: 'a',
                    status: 'completed',
                    result: {
                        document_type: 'passport',
                        confidence: 0.9,
                        fields: {},
                        warnings: [],
                    },
                },
                { id: 3, draft_id: 'b', status: 'failed', error: 'safe' },
                { id: 4, draft_id: 'old', status: 'completed' },
            ],
        },
        emptyDocumentAiBatch(),
        new Set(['a', 'b']),
    );
    assert.equal(state.items.a.status, 'ready');
    assert.equal(state.items.b.status, 'failed');
    assert.equal(state.items.old, undefined);
});
test('retry resets only failed item', () => {
    const state = {
        id: 1,
        status: 'completed_with_errors' as const,
        items: {
            a: {
                itemId: 1,
                draftId: 'a',
                status: 'failed' as const,
                error: 'x',
            },
            b: { itemId: 2, draftId: 'b', status: 'ready' as const },
        },
    };
    const next = resetFailedItem(state, 'a');
    assert.equal(next.items.a.status, 'queued');
    assert.equal(next.items.b.status, 'ready');
});
