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

export async function compressUploadFile(file: File): Promise<File> {
    if (!isCompressibleImage(file) || file.size < MIN_IMAGE_COMPRESS_BYTES) {
        return file;
    }

    try {
        const compressed = await imageCompression(file, {
            maxSizeMB: IMAGE_COMPRESS_MAX_SIZE_MB,
            maxWidthOrHeight: IMAGE_COMPRESS_MAX_DIMENSION,
            useWebWorker: true,
            initialQuality: 0.82,
            fileType: file.type,
        });

        if (compressed.size >= file.size) {
            return file;
        }

        return compressed;
    } catch {
        return file;
    }
}

export async function prepareUploadFiles(files: File[]): Promise<File[]> {
    return Promise.all(files.map((file) => compressUploadFile(file)));
}
