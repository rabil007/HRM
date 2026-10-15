export function normalizeDocumentPreviewMimeType(
    mimeType: string | null | undefined,
): string | null {
    if (typeof mimeType !== 'string' || mimeType.trim() === '') {
        return null;
    }

    const base = mimeType.split(';')[0]?.trim().toLowerCase() ?? '';

    return base === '' ? null : base;
}

export function resolveDocumentPreviewMimeType(
    mimeType: string | null | undefined,
    fileUrl: string,
    originalFilename?: string | null,
): string | null {
    const normalizedStoredMime = normalizeDocumentPreviewMimeType(mimeType);

    if (normalizedStoredMime !== null) {
        return normalizedStoredMime;
    }

    const filename = originalFilename?.trim().toLowerCase() ?? '';

    if (filename.endsWith('.pdf')) {
        return 'application/pdf';
    }

    if (filename.endsWith('.png')) {
        return 'image/png';
    }

    if (filename.endsWith('.jpg') || filename.endsWith('.jpeg')) {
        return 'image/jpeg';
    }

    if (filename.endsWith('.gif')) {
        return 'image/gif';
    }

    if (filename.endsWith('.webp')) {
        return 'image/webp';
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

export type DocumentPreviewRenderMode = 'image' | 'pdf' | 'unsupported';

export function resolveDocumentPreviewRenderMode(input: {
    mime_type?: string | null;
    file_url: string;
    original_filename?: string | null;
    can_preview?: boolean;
}): DocumentPreviewRenderMode {
    if (input.can_preview === false) {
        return 'unsupported';
    }

    const mimeType = resolveDocumentPreviewMimeType(
        input.mime_type,
        input.file_url,
        input.original_filename,
    );

    if (mimeType === null) {
        return 'unsupported';
    }

    if (mimeType.startsWith('image/')) {
        return 'image';
    }

    if (mimeType === 'application/pdf') {
        return 'pdf';
    }

    return 'unsupported';
}

export function documentPreviewIsAvailable(input: {
    mime_type?: string | null;
    file_url: string;
    original_filename?: string | null;
    can_preview?: boolean;
}): boolean {
    return resolveDocumentPreviewRenderMode(input) !== 'unsupported';
}
