import type { ReactElement } from 'react';

export type DocumentPreviewSource = {
    title: string | null;
    document_type_label?: string | null;
    file_url: string;
    mime_type?: string | null;
    can_preview?: boolean;
};

function resolveMimeType(
    mimeType: string | null | undefined,
    fileUrl: string,
): string | null {
    if (mimeType) {
        return mimeType;
    }

    const path = fileUrl.split('?')[0]?.toLowerCase() ?? '';

    if (path.endsWith('.pdf')) {
        return 'application/pdf';
    }

    if (path.endsWith('.png')) {
        return 'image/png';
    }

    if (path.endsWith('.jpg') || path.endsWith('.jpeg')) {
        return 'image/jpeg';
    }

    if (path.endsWith('.gif')) {
        return 'image/gif';
    }

    if (path.endsWith('.webp')) {
        return 'image/webp';
    }

    return null;
}

export function DocumentPreviewPanel({
    document,
    className = 'h-[70vh]',
}: {
    document: DocumentPreviewSource;
    className?: string;
}): ReactElement {
    const mimeType = resolveMimeType(document.mime_type, document.file_url);
    const isImage = mimeType?.startsWith('image/') ?? false;
    const isPdf = mimeType === 'application/pdf';
    const canPreview =
        document.can_preview !== false && (isImage || isPdf);

    return (
        <div
            className={`overflow-hidden rounded-xl border border-border bg-muted/20 ${className}`}
        >
            {canPreview ? (
                isImage ? (
                    <img
                        src={document.file_url}
                        alt={document.title ?? 'Document'}
                        className="h-full w-full object-contain"
                    />
                ) : (
                    <iframe
                        src={document.file_url}
                        title={document.title ?? 'Document preview'}
                        className="h-full w-full"
                    />
                )
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
