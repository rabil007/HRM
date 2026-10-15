import type { ReactElement } from 'react';
import { resolveDocumentPreviewRenderMode } from '@/features/organization/documents/lib/document-preview-mime';
import { pdfEmbedUrl } from '@/features/organization/documents/lib/pdf-embed-url';

export type DocumentPreviewSource = {
    title: string | null;
    document_type_label?: string | null;
    file_url: string;
    mime_type?: string | null;
    original_filename?: string | null;
    can_preview?: boolean;
};

export function DocumentPreviewPanel({
    document,
    className = 'h-[70vh]',
}: {
    document: DocumentPreviewSource;
    className?: string;
}): ReactElement {
    const renderMode = resolveDocumentPreviewRenderMode(document);

    return (
        <div
            className={`overflow-hidden rounded-xl border border-border bg-muted/20 ${className}`}
        >
            {renderMode === 'image' ? (
                <img
                    src={document.file_url}
                    alt={document.title ?? 'Document'}
                    className="h-full w-full object-contain"
                />
            ) : renderMode === 'pdf' ? (
                <iframe
                    src={pdfEmbedUrl(document.file_url)}
                    title={document.title ?? 'Document preview'}
                    className="h-full w-full"
                />
            ) : (
                <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
                    <p>Preview is not available for this file type.</p>
                    <a
                        href={document.file_url}
                        target="_blank"
                        rel="noreferrer"
                        className="font-medium text-primary hover:underline"
                    >
                        Open file
                    </a>
                </div>
            )}
        </div>
    );
}
