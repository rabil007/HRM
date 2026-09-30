import type { DocumentAiReviewState } from './document-ai-review';

export type DocumentAiBatchItemState = {
    itemId: number;
    draftId: string;
    status: 'queued' | 'processing' | 'ready' | 'failed' | 'cancelled';
    review?: DocumentAiReviewState;
    error?: string;
};

export type DocumentAiBatchState = {
    id: number | null;
    status:
        | 'idle'
        | 'pending'
        | 'processing'
        | 'completed'
        | 'completed_with_errors'
        | 'cancelled'
        | 'expired'
        | 'failed';
    items: Record<string, DocumentAiBatchItemState>;
    requestId: string | null;
};

export const emptyDocumentAiBatch = (): DocumentAiBatchState => ({
    id: null,
    status: 'idle',
    items: {},
    requestId: null,
});

export function isActiveBatchStatus(
    status: DocumentAiBatchState['status'],
): boolean {
    return status === 'pending' || status === 'processing';
}

export function mapBatchResponse(
    payload: any,
    current: DocumentAiBatchState,
    validDraftIds: Set<string>,
): DocumentAiBatchState {
    const items = { ...current.items };

    for (const item of payload.items ?? []) {
        if (!validDraftIds.has(item.draft_id)) {
            continue;
        }

        items[item.draft_id] = {
            itemId: item.id,
            draftId: item.draft_id,
            status: item.status === 'completed' ? 'ready' : item.status,
            review: item.result
                ? {
                      status: 'ready',
                      contextKey: null,
                      detectedDocumentType: item.result.document_type,
                      overallConfidence: item.result.confidence,
                      fields: item.result.fields,
                      warnings: item.result.warnings,
                  }
                : undefined,
            error: item.error ?? undefined,
        };
    }

    return {
        id: payload.id,
        status: payload.status,
        items,
        requestId: current.requestId,
    };
}

export function resetFailedItem(
    state: DocumentAiBatchState,
    draftId: string,
): DocumentAiBatchState {
    const item = state.items[draftId];

    if (!item || item.status !== 'failed') {
        return state;
    }

    return {
        ...state,
        status: isActiveBatchStatus(state.status) ? state.status : 'processing',
        items: {
            ...state.items,
            [draftId]: { ...item, status: 'queued', error: undefined },
        },
    };
}
