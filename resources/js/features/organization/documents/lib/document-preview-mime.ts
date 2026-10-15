export function resolveDocumentPreviewMimeType(
    mimeType: string | null | undefined,
    fileUrl: string,
    originalFilename?: string | null,
): string | null {
    if (mimeType) {
        return mimeType;
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

export function documentPreviewIsAvailable(input: {
    mime_type?: string | null;
    file_url: string;
    original_filename?: string | null;
    can_preview?: boolean;
}): boolean {
    const mimeType = resolveDocumentPreviewMimeType(
        input.mime_type,
        input.file_url,
        input.original_filename,
    );
    const isImage = mimeType?.startsWith('image/') ?? false;
    const isPdf = mimeType === 'application/pdf';

    return (
        input.can_preview === true ||
        (input.can_preview !== false && (isImage || isPdf))
    );
}
