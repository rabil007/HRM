import { useCallback, useEffect, useMemo, useState } from 'react';
import * as BatchController from '@/actions/App/Http/Controllers/Organization/DocumentAiBatchController';
import {
    emptyDocumentAiBatch,
    mapBatchResponse,
    resetFailedItem,
} from '@/features/organization/documents/lib/document-ai-batch-state';
import type { UploadDraft } from './upload-draft';

const csrf = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';
export function useDocumentAiBatch(
    drafts: UploadDraft[],
    employeeId: number | null,
) {
    const [state, setState] = useState(emptyDocumentAiBatch);
    const validDraftIds = useMemo(
        () => new Set(drafts.map((draft) => draft.id)),
        [drafts],
    );
    const start = useCallback(async () => {
        if (!employeeId || drafts.length < 2) {
            return;
        }

        const data = new FormData();
        drafts.forEach((d) => {
            data.append('files[]', d.file);
            data.append('draft_ids[]', d.id);
        });
        const r = await fetch(
            BatchController.store.url({ employee: employeeId }),
            {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                body: data,
            },
        );

        if (!r.ok) {
            throw new Error('batch failed');
        }

        const p = await r.json();
        setState(
            mapBatchResponse(p.batch, emptyDocumentAiBatch(), validDraftIds),
        );
    }, [drafts, employeeId, validDraftIds]);
    useEffect(() => {
        if (!state.id || !['pending', 'processing'].includes(state.status)) {
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
            const item = state.items[draftId];

            if (!state.id || !item) {
                return;
            }

            setState((s) => resetFailedItem(s, draftId));
            await fetch(
                BatchController.retry.url({
                    batch: state.id,
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
        },
        [state],
    );
    const cancel = useCallback(async () => {
        if (!state.id) {
            return;
        }

        await fetch(BatchController.cancel.url({ batch: state.id }), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        });
        setState((s) => ({ ...s, status: 'cancelled' }));
    }, [state.id]);
    const reset = useCallback(() => setState(emptyDocumentAiBatch()), []);
    const purge = useCallback(async () => {
        if (state.id) {
            await fetch(BatchController.destroy.url({ batch: state.id }), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            });
        }

        setState(emptyDocumentAiBatch());
    }, [state.id]);

    return { state, start, retry, cancel, reset, purge };
}
