import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as BatchController from '@/actions/App/Http/Controllers/Organization/DocumentAiBatchController';
import {
    emptyDocumentAiBatch,
    failedStartPreservingRequestId,
    isActiveBatchStatus,
    mapBatchResponse,
    resetFailedItem,
    resolveBatchRequestId,
} from '@/features/organization/documents/lib/document-ai-batch-state';
import type { DocumentAiBatchState } from '@/features/organization/documents/lib/document-ai-batch-state';
import type { UploadDraft } from './upload-draft';

const csrf = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

function newBatchRequestId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const value = (Math.random() * 16) | 0;
        const digit = char === 'x' ? value : (value & 0x3) | 0x8;

        return digit.toString(16);
    });
}

export function useDocumentAiBatch(
    drafts: UploadDraft[],
    employeeId: number | null,
) {
    const [state, setState] = useState(emptyDocumentAiBatch);
    const stateRef = useRef(state);
    const startingRef = useRef(false);
    const retryingRef = useRef<string | null>(null);
    const validDraftIds = useMemo(
        () => new Set(drafts.map((draft) => draft.id)),
        [drafts],
    );

    useEffect(() => {
        stateRef.current = state;
    }, [state]);

    const draftIdentityKey = useMemo(
        () =>
            drafts
                .map((draft) => draft.id)
                .sort()
                .join('|'),
        [drafts],
    );
    const draftIdentityRef = useRef(draftIdentityKey);
    const employeeRef = useRef(employeeId);

    useEffect(() => {
        if (draftIdentityRef.current === draftIdentityKey) {
            return;
        }

        draftIdentityRef.current = draftIdentityKey;
        setState((current) =>
            current.status === 'failed' && current.requestId
                ? { ...current, requestId: null }
                : current,
        );
    }, [draftIdentityKey]);

    useEffect(() => {
        if (employeeRef.current === employeeId) {
            return;
        }

        employeeRef.current = employeeId;
        setState(emptyDocumentAiBatch());
    }, [employeeId]);

    const start = useCallback(async () => {
        if (
            !employeeId ||
            drafts.length < 2 ||
            startingRef.current ||
            (state.status !== 'idle' && state.status !== 'failed')
        ) {
            return;
        }

        startingRef.current = true;
        const requestId = resolveBatchRequestId(
            stateRef.current.requestId,
            newBatchRequestId,
        );
        setState({
            id: null,
            status: 'pending',
            items: {},
            requestId,
        });

        try {
            const data = new FormData();
            data.append('batch_request_id', requestId);
            drafts.forEach((d) => {
                data.append('files[]', d.file);
                data.append('draft_ids[]', d.id);
            });
            const r = await fetch(
                BatchController.store.url({ employee: employeeId }),
                {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf(),
                        Accept: 'application/json',
                    },
                    body: data,
                },
            );

            if (!r.ok) {
                throw new Error('batch failed');
            }

            const p = await r.json();
            setState(
                mapBatchResponse(
                    p.batch,
                    {
                        ...emptyDocumentAiBatch(),
                        requestId,
                    },
                    validDraftIds,
                ),
            );
        } catch {
            // Preserve requestId so an ambiguous network failure can reuse the
            // same server-side idempotency key instead of creating duplicates.
            setState(failedStartPreservingRequestId(requestId));
        } finally {
            startingRef.current = false;
        }
    }, [drafts, employeeId, state.status, validDraftIds]);

    useEffect(() => {
        if (!state.id || !isActiveBatchStatus(state.status)) {
            return;
        }

        const timer = window.setInterval(async () => {
            try {
                const r = await fetch(
                    BatchController.show.url({ batch: state.id! }),
                    { headers: { Accept: 'application/json' } },
                );

                if (r.ok) {
                    const p = await r.json();
                    setState((s) =>
                        mapBatchResponse(p.batch, s, validDraftIds),
                    );
                }
            } catch {
                // Polling retries on the next interval; manual upload remains available.
            }
        }, 2500);

        return () => window.clearInterval(timer);
    }, [state.id, state.status, validDraftIds]);

    const retry = useCallback(
        async (draftId: string) => {
            const current = stateRef.current;
            const item = current.items[draftId];

            if (
                !current.id ||
                !item ||
                item.status !== 'failed' ||
                retryingRef.current === draftId
            ) {
                return;
            }

            retryingRef.current = draftId;
            const previous = current;
            setState((s) => resetFailedItem(s, draftId));

            try {
                const r = await fetch(
                    BatchController.retry.url({
                        batch: current.id,
                        item: item.itemId,
                    }),
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf(),
                            Accept: 'application/json',
                        },
                    },
                );

                if (!r.ok) {
                    throw new Error('retry failed');
                }

                const p = await r.json();
                setState((s) => mapBatchResponse(p.batch, s, validDraftIds));
            } catch {
                try {
                    const show = await fetch(
                        BatchController.show.url({ batch: current.id }),
                        { headers: { Accept: 'application/json' } },
                    );

                    if (show.ok) {
                        const p = await show.json();
                        setState((s) =>
                            mapBatchResponse(p.batch, s, validDraftIds),
                        );

                        return;
                    }
                } catch {
                    // Fall through to previous local state.
                }

                setState(previous);
            } finally {
                retryingRef.current = null;
            }
        },
        [validDraftIds],
    );

    const cancel = useCallback(async () => {
        const current = stateRef.current;

        if (!current.id) {
            return;
        }

        try {
            await fetch(BatchController.cancel.url({ batch: current.id }), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                },
            });
        } catch {
            // Cancellation failure must not block manual upload.
        }

        setState((s) => ({ ...s, status: 'cancelled' }));
    }, []);

    const reset = useCallback(() => setState(emptyDocumentAiBatch()), []);

    const purge = useCallback(async () => {
        const current = stateRef.current;

        if (current.id) {
            try {
                await fetch(
                    BatchController.destroy.url({ batch: current.id }),
                    {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrf(),
                            Accept: 'application/json',
                        },
                    },
                );
            } catch {
                // Purge failure must not block dialog close / manual upload.
            }
        }

        setState(emptyDocumentAiBatch());
    }, []);

    const abandon = useCallback(async () => {
        const current = stateRef.current;

        if (!current.id) {
            setState(emptyDocumentAiBatch());

            return;
        }

        if (isActiveBatchStatus(current.status)) {
            try {
                await fetch(BatchController.cancel.url({ batch: current.id }), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf(),
                        Accept: 'application/json',
                    },
                });
            } catch {
                // Continue clearing local state even if cancel fails.
            }
        }

        try {
            await fetch(BatchController.destroy.url({ batch: current.id }), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                },
            });
        } catch {
            // Destroy failure must not block local reset.
        }

        setState(emptyDocumentAiBatch());
    }, []);

    return {
        state,
        start,
        retry,
        cancel,
        reset,
        purge,
        abandon,
        isActive: isActiveBatchStatus(state.status),
    };
}

export type { DocumentAiBatchState };
