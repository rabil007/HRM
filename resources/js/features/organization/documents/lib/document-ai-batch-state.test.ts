import assert from 'node:assert/strict';
import test from 'node:test';
import {
    emptyDocumentAiBatch,
    failedStartPreservingRequestId,
    isActiveBatchStatus,
    mapBatchResponse,
    resetFailedItem,
    resolveBatchRequestId,
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

test('retry from completed_with_errors becomes processing and polling-eligible', () => {
    const prior = {
        id: 1,
        status: 'completed_with_errors' as const,
        requestId: 'req',
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

    const optimistic = resetFailedItem(prior, 'a');
    assert.equal(optimistic.status, 'processing');
    assert.equal(optimistic.items.a.status, 'queued');
    assert.equal(isActiveBatchStatus(optimistic.status), true);

    const afterRetryResponse = mapBatchResponse(
        {
            id: 1,
            status: 'processing',
            items: [
                { id: 1, draft_id: 'a', status: 'queued' },
                {
                    id: 2,
                    draft_id: 'b',
                    status: 'completed',
                    result: {
                        document_type: 'passport',
                        confidence: 0.9,
                        fields: {},
                        warnings: [],
                    },
                },
            ],
        },
        optimistic,
        new Set(['a', 'b']),
    );

    assert.equal(afterRetryResponse.status, 'processing');
    assert.equal(isActiveBatchStatus(afterRetryResponse.status), true);

    const completed = mapBatchResponse(
        {
            id: 1,
            status: 'completed',
            items: [
                {
                    id: 1,
                    draft_id: 'a',
                    status: 'completed',
                    result: {
                        document_type: 'passport',
                        confidence: 0.95,
                        fields: {
                            document_number: {
                                value: 'P9',
                                confidence: 0.95,
                            },
                        },
                        warnings: [],
                    },
                },
                {
                    id: 2,
                    draft_id: 'b',
                    status: 'completed',
                    result: {
                        document_type: 'passport',
                        confidence: 0.9,
                        fields: {},
                        warnings: [],
                    },
                },
            ],
        },
        afterRetryResponse,
        new Set(['a', 'b']),
    );

    assert.equal(completed.items.a.status, 'ready');
    assert.equal(completed.items.a.review?.fields.document_number?.value, 'P9');
});

test('retry resets only failed item', () => {
    const state = {
        id: 1,
        status: 'completed_with_errors' as const,
        requestId: null,
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

test('removed draft ids ignore late batch results', () => {
    const state = mapBatchResponse(
        {
            id: 9,
            status: 'completed',
            items: [
                {
                    id: 1,
                    draft_id: 'kept',
                    status: 'completed',
                    result: {
                        document_type: 'unknown',
                        confidence: 0.1,
                        fields: {},
                        warnings: [],
                    },
                },
                {
                    id: 2,
                    draft_id: 'removed',
                    status: 'completed',
                    result: {
                        document_type: 'passport',
                        confidence: 0.9,
                        fields: {},
                        warnings: [],
                    },
                },
            ],
        },
        emptyDocumentAiBatch(),
        new Set(['kept']),
    );

    assert.equal(state.items.kept.status, 'ready');
    assert.equal(state.items.removed, undefined);
});

test('abandoned bulk state ignores late poll payloads for remaining draft', () => {
    // After bulk → single, abandon resets to empty before any late poll applies.
    const abandoned = emptyDocumentAiBatch();
    assert.equal(abandoned.id, null);
    assert.equal(abandoned.requestId, null);
    assert.equal(isActiveBatchStatus(abandoned.status), false);

    const late = mapBatchResponse(
        {
            id: 42,
            status: 'completed',
            items: [
                {
                    id: 7,
                    draft_id: 'remaining',
                    status: 'completed',
                    result: {
                        document_type: 'passport',
                        confidence: 0.99,
                        fields: {
                            document_number: {
                                value: 'SHOULD-NOT-STICK',
                                confidence: 0.99,
                            },
                        },
                        warnings: [],
                    },
                },
            ],
        },
        abandoned,
        // Single-file mode no longer treats the draft as part of an active bulk set.
        new Set(),
    );

    assert.equal(late.items.remaining, undefined);
    assert.equal(Object.keys(late.items).length, 0);
});

test('failed start preserves requestId for ambiguous retry', () => {
    const failed = failedStartPreservingRequestId('uuid-x');
    assert.equal(failed.status, 'failed');
    assert.equal(failed.requestId, 'uuid-x');
    assert.equal(
        resolveBatchRequestId(failed.requestId, () => 'new'),
        'uuid-x',
    );
    assert.equal(
        resolveBatchRequestId(emptyDocumentAiBatch().requestId, () => 'new'),
        'new',
    );
    assert.equal(emptyDocumentAiBatch().requestId, null);
});
