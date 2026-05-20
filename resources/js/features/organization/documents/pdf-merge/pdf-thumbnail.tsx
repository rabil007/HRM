import { FileText } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { loadPdfPreview } from '@/features/organization/documents/pdf-merge/pdf-preview-service';
import type { MergeDocumentItem, PdfPreviewData } from '@/features/organization/documents/pdf-merge/types';
import { cn } from '@/lib/utils';

export function PdfThumbnail({
    document,
    className,
    onPreviewLoaded,
}: {
    document: MergeDocumentItem;
    className?: string;
    onPreviewLoaded?: (data: PdfPreviewData) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [thumbnailUrl, setThumbnailUrl] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [hasError, setHasError] = useState(false);

    useEffect(() => {
        const element = containerRef.current;

        if (!element) {
            return;
        }

        let cancelled = false;

        const observer = new IntersectionObserver(
            (entries) => {
                const entry = entries[0];

                if (!entry?.isIntersecting || cancelled) {
                    return;
                }

                observer.disconnect();
                setIsLoading(true);

                loadPdfPreview(document.id, document.file_url)
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
            },
            { rootMargin: '120px' },
        );

        observer.observe(element);

        return () => {
            cancelled = true;
            observer.disconnect();
        };
    }, [document.file_url, document.id, onPreviewLoaded]);

    return (
        <div
            ref={containerRef}
            className={cn(
                'relative flex h-24 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-zinc-950/60',
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
                        'h-8 w-8',
                        hasError ? 'text-zinc-600' : 'text-zinc-500',
                    )}
                />
            )}
        </div>
    );
}
