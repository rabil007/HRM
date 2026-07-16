import { FileText, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactElement } from 'react';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { getPdfJs } from '@/lib/pdfjs';
import { cn } from '@/lib/utils';

type SharedDocumentPreviewProps = {
    title: string;
    mimeType: string | null;
    previewUrl: string | null;
    canPreview: boolean;
    allowDownload: boolean;
    className?: string;
};

function isPdf(mimeType: string | null): boolean {
    return mimeType === 'application/pdf';
}

function isImage(mimeType: string | null): boolean {
    return Boolean(mimeType?.startsWith('image/'));
}

function PdfCanvasPreview({
    url,
    className,
}: {
    url: string;
    className?: string;
}): ReactElement {
    const containerRef = useRef<HTMLDivElement>(null);
    const pagesRef = useRef<HTMLDivElement>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [pageCount, setPageCount] = useState(0);

    useEffect(() => {
        let cancelled = false;

        const render = async () => {
            setIsLoading(true);
            setError(null);
            setPageCount(0);

            const pagesNode = pagesRef.current;

            if (pagesNode) {
                pagesNode.replaceChildren();
            }

            try {
                const width = containerRef.current?.clientWidth || 720;
                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error('Failed to load PDF.');
                }

                const data = await response.arrayBuffer();
                const pdfjs = await getPdfJs();
                const pdf = await pdfjs.getDocument({ data }).promise;

                if (cancelled) {
                    return;
                }

                setPageCount(pdf.numPages);

                for (
                    let pageNumber = 1;
                    pageNumber <= pdf.numPages;
                    pageNumber++
                ) {
                    if (cancelled || !pagesRef.current) {
                        return;
                    }

                    const page = await pdf.getPage(pageNumber);
                    const baseViewport = page.getViewport({ scale: 1 });
                    const scale = Math.min(2, width / baseViewport.width);
                    const viewport = page.getViewport({ scale });
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');

                    if (!context) {
                        throw new Error('Could not render PDF preview.');
                    }

                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.className =
                        'mx-auto w-full max-w-full rounded-md bg-white shadow-sm';

                    await page.render({
                        canvasContext: context,
                        viewport,
                        canvas,
                    }).promise;

                    pagesRef.current.appendChild(canvas);
                }

                if (!cancelled) {
                    setIsLoading(false);
                }
            } catch (loadError) {
                if (!cancelled) {
                    setError(
                        loadError instanceof Error
                            ? loadError.message
                            : 'Failed to load PDF preview.',
                    );
                    setIsLoading(false);
                }
            }
        };

        void render();

        return () => {
            cancelled = true;
        };
    }, [url]);

    return (
        <div
            ref={containerRef}
            className={cn(
                'overflow-auto rounded-xl border border-zinc-800 bg-zinc-950/50',
                className,
            )}
            onContextMenu={(event) => event.preventDefault()}
        >
            {isLoading ? (
                <div className="flex h-full min-h-[320px] items-center justify-center gap-2 text-sm text-zinc-500">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Loading preview…
                </div>
            ) : null}

            {error ? (
                <div className="flex h-full min-h-[320px] flex-col items-center justify-center gap-2 px-4 text-center text-sm text-zinc-500">
                    <FileText className="h-8 w-8 text-zinc-700" />
                    <p>{error}</p>
                </div>
            ) : null}

            <div
                ref={pagesRef}
                className={cn(
                    'space-y-4 p-3',
                    (isLoading || error) && 'hidden',
                )}
                aria-label={
                    pageCount > 0
                        ? `PDF preview, ${pageCount} pages`
                        : 'PDF preview'
                }
            />
        </div>
    );
}

export function SharedDocumentPreview({
    title,
    mimeType,
    previewUrl,
    canPreview,
    allowDownload,
    className = 'h-[min(70vh,640px)]',
}: SharedDocumentPreviewProps): ReactElement {
    if (!previewUrl || !canPreview) {
        return (
            <div
                className={cn(
                    'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-800 text-sm text-zinc-500',
                    className,
                )}
            >
                <DocumentFileIcon
                    mimeType={mimeType}
                    fileName={title}
                    className="h-10 w-10"
                />
                <p>Preview is not available for this file.</p>
            </div>
        );
    }

    if (isPdf(mimeType)) {
        return <PdfCanvasPreview url={previewUrl} className={className} />;
    }

    if (isImage(mimeType)) {
        return (
            <div
                className={cn(
                    'overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950/50',
                    className,
                )}
                onContextMenu={
                    allowDownload
                        ? undefined
                        : (event) => event.preventDefault()
                }
            >
                <img
                    src={previewUrl}
                    alt={title}
                    draggable={allowDownload}
                    className="h-full w-full object-contain select-none"
                />
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-800 text-sm text-zinc-500',
                className,
            )}
        >
            <DocumentFileIcon
                mimeType={mimeType}
                fileName={title}
                className="h-10 w-10"
            />
            <p>Preview is not available for this file.</p>
        </div>
    );
}
