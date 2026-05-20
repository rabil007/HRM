import { GripVertical } from 'lucide-react';

import { PdfThumbnail } from '@/features/organization/documents/pdf-merge/pdf-thumbnail';
import type { MergeDocumentItem } from '@/features/organization/documents/pdf-merge/types';
import { formatBytes } from '@/lib/utils';

type MergeListItemProps = {
    document: MergeDocumentItem;
    index: number;
    pageCount: number | null;
    onPreviewLoaded?: (documentId: number, pageCount: number) => void;
};

export function MergeListItem({
    document,
    index,
    pageCount,
    onPreviewLoaded,
}: MergeListItemProps) {
    return (
        <div
            data-id={document.id}
            className="flex items-center gap-4 rounded-xl border border-white/10 bg-zinc-900/80 p-4 transition-colors"
        >
            <button
                type="button"
                className="drag-handle flex shrink-0 cursor-grab touch-none items-center text-zinc-500 hover:text-zinc-300 active:cursor-grabbing"
                aria-label={`Reorder ${document.document_name}`}
            >
                <GripVertical className="h-5 w-5" />
            </button>

            <span className="w-6 shrink-0 text-sm font-medium text-zinc-500">{index + 1}</span>

            <PdfThumbnail
                document={document}
                onPreviewLoaded={(preview) => onPreviewLoaded?.(document.id, preview.pageCount)}
            />

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-zinc-100">
                    {document.document_name}
                </p>
                <p className="mt-1 text-xs text-zinc-400">
                    {pageCount !== null ? `${pageCount} page${pageCount === 1 ? '' : 's'}` : '— pages'}
                    {' • '}
                    {formatBytes(document.size_bytes)}
                </p>
            </div>
        </div>
    );
}
