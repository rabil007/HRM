import { FileText } from 'lucide-react';
import { useEffect, useState } from 'react';

import { loadPdfPreview } from '@/features/organization/documents/pdf-merge/pdf-preview-service';
import type { MergeDocumentItem, PdfPreviewData } from '@/features/organization/documents/pdf-merge/types';
import { cn } from '@/lib/utils';

function PdfThumbnailContent({
    document,
    className,
    onPreviewLoaded,
}: {
    document: MergeDocumentItem;
    className?: string;
    onPreviewLoaded?: (data: PdfPreviewData) => void;
}) {
    const [thumbnailUrl, setThumbnailUrl] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [hasError, setHasError] = useState(false);

    useEffect(() => {
        let cancelled = false;

        loadPdfPreview(document.id)
            .then((preview) => {
                if (!cancelled) {
                    setThumbnailUrl(preview.thumbnailDataUrl);
                    setHasError(preview.thumbnailDataUrl === null);
                    onPreviewLoaded?.(preview);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setHasError(true);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setIsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [document.id, onPreviewLoaded]);

    return (
        <div
            className={cn(
                'relative flex h-16 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-white/10 bg-zinc-950/60',
                className,
            )}
        >
            {isLoading ? (
                <div className="h-full w-full animate-pulse bg-white/5" />
            ) : thumbnailUrl ? (
                <img
                    src={thumbnailUrl}
                    alt=""
                    className="h-full w-full object-cover object-top"
                />
            ) : (
                <FileText
                    className={cn(
                        'h-6 w-6',
                        hasError ? 'text-zinc-600' : 'text-zinc-500',
                    )}
                />
            )}
        </div>
    );
}

export function PdfThumbnail({
    document,
    className,
    onPreviewLoaded,
}: {
    document: MergeDocumentItem;
    className?: string;
    onPreviewLoaded?: (data: PdfPreviewData) => void;
}) {
    return (
        <PdfThumbnailContent
            key={document.id}
            document={document}
            className={className}
            onPreviewLoaded={onPreviewLoaded}
        />
    );
}
