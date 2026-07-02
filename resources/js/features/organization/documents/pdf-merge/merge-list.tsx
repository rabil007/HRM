import { useEffect, useRef } from 'react';
import Sortable from 'sortablejs';

import { MergeListItem } from '@/features/organization/documents/pdf-merge/merge-list-item';
import type { MergeDocumentItem } from '@/features/organization/documents/pdf-merge/types';

type MergeListProps = {
    documents: MergeDocumentItem[];
    pageCounts: Record<number, number | null>;
    onReorder: (orderedIds: number[]) => void;
    onPreviewLoaded?: (documentId: number, pageCount: number) => void;
};

export function MergeList({
    documents,
    pageCounts,
    onReorder,
    onPreviewLoaded,
}: MergeListProps) {
    const listRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = listRef.current;

        if (!element) {
            return;
        }

        const sortable = Sortable.create(element, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'opacity-50',
            touchStartThreshold: 5,
            onEnd: () => {
                const orderedIds = Array.from(element.children)
                    .map((child) => Number(child.getAttribute('data-id')))
                    .filter((id) => Number.isFinite(id));

                onReorder(orderedIds);
            },
        });

        return () => {
            sortable.destroy();
        };
    }, [onReorder]);

    return (
        <div ref={listRef} className="flex flex-col gap-2">
            {documents.map((document, index) => (
                <MergeListItem
                    key={document.id}
                    document={document}
                    index={index}
                    pageCount={pageCounts[document.id] ?? null}
                    onPreviewLoaded={onPreviewLoaded}
                />
            ))}
        </div>
    );
}
