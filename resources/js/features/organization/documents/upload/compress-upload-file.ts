import imageCompression from 'browser-image-compression';

import {
    IMAGE_COMPRESS_MAX_DIMENSION,
    IMAGE_COMPRESS_MAX_SIZE_MB,
    MIN_IMAGE_COMPRESS_BYTES,
    SUPPORTED_UPLOAD_MIME_TYPES,
} from '@/features/organization/documents/upload/upload-draft';

function isCompressibleImage(file: File): boolean {
    return file.type === 'image/jpeg' || file.type === 'image/png';
}

export function isSupportedUploadFile(file: File): boolean {
    return (SUPPORTED_UPLOAD_MIME_TYPES as readonly string[]).includes(
        file.type,
    );
}

/**
 * Strip characters that break multipart uploads behind ModSecurity/WAF
 * (e.g. apostrophes in `SEAMAN'S BOOK.pdf` → HTTP 403).
 */
export function sanitizeUploadFilename(filename: string): string {
    const trimmed = filename.trim();
    const lastDot = trimmed.lastIndexOf('.');
    const base = lastDot > 0 ? trimmed.slice(0, lastDot) : trimmed;
    const extension = lastDot > 0 ? trimmed.slice(lastDot) : '';

    const safeBase =
        base
            .replace(/['"`´’‘]/g, '')
            .replace(/[^\w.\- ()[\]]+/gu, '-')
            .replace(/[- ]{2,}/g, (match) => (match.includes('-') ? '-' : ' '))
            .replace(/^[\s.-]+|[\s.-]+$/g, '') || 'document';

    const safeExtension = extension.replace(/[^.\w]+/g, '');

    return `${safeBase}${safeExtension}`;
}

export function withSanitizedUploadFilename(file: File): File {
    const safeName = sanitizeUploadFilename(file.name);

    if (safeName === file.name) {
        return file;
    }

    return new File([file], safeName, {
        type: file.type,
        lastModified: file.lastModified,
    });
}

export async function compressUploadFile(file: File): Promise<File> {
    const uploadFile = withSanitizedUploadFilename(file);

    if (
        !isCompressibleImage(uploadFile) ||
        uploadFile.size < MIN_IMAGE_COMPRESS_BYTES
    ) {
        return uploadFile;
    }

    try {
        const compressed = await imageCompression(uploadFile, {
            maxSizeMB: IMAGE_COMPRESS_MAX_SIZE_MB,
            maxWidthOrHeight: IMAGE_COMPRESS_MAX_DIMENSION,
            useWebWorker: true,
            initialQuality: 0.82,
            fileType: uploadFile.type,
        });

        if (compressed.size >= uploadFile.size) {
            return uploadFile;
        }

        return withSanitizedUploadFilename(
            new File([compressed], uploadFile.name, {
                type: compressed.type || uploadFile.type,
                lastModified: Date.now(),
            }),
        );
    } catch {
        return uploadFile;
    }
}

export async function prepareUploadFiles(files: File[]): Promise<File[]> {
    return Promise.all(files.map((file) => compressUploadFile(file)));
}
