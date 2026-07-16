const ALLOWED_TYPES = new Set([
    'image/png',
    'image/jpeg',
    'image/jpg',
    'image/webp',
]);

const MAX_FILE_BYTES = 4 * 1024 * 1024;
const MAX_DATA_URL_LENGTH = 480_000;
const MAX_OUTPUT_EDGE = 1200;

export function isAllowedSignatureImage(file: File): boolean {
    return ALLOWED_TYPES.has(file.type);
}

export async function fileToSignatureDataUrl(file: File): Promise<string> {
    if (!isAllowedSignatureImage(file)) {
        throw new Error('Use a PNG, JPG, or WebP image.');
    }

    if (file.size > MAX_FILE_BYTES) {
        throw new Error('Image must be 4 MB or smaller.');
    }

    const objectUrl = URL.createObjectURL(file);

    try {
        const image = await loadImage(objectUrl);
        const { width, height } = fitWithin(
            image.width,
            image.height,
            MAX_OUTPUT_EDGE,
        );
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d');

        if (!context) {
            throw new Error('Could not process signature image.');
        }

        context.clearRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);

        let quality = 0.92;
        let dataUrl = canvas.toDataURL('image/png');

        if (dataUrl.length > MAX_DATA_URL_LENGTH) {
            dataUrl = canvas.toDataURL('image/jpeg', quality);

            while (dataUrl.length > MAX_DATA_URL_LENGTH && quality > 0.45) {
                quality -= 0.1;
                dataUrl = canvas.toDataURL('image/jpeg', quality);
            }
        }

        if (dataUrl.length > MAX_DATA_URL_LENGTH) {
            throw new Error(
                'Image is too large after compression. Try a simpler signature image.',
            );
        }

        return dataUrl;
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

function fitWithin(
    width: number,
    height: number,
    maxEdge: number,
): { width: number; height: number } {
    const longest = Math.max(width, height);

    if (longest <= maxEdge) {
        return { width, height };
    }

    const scale = maxEdge / longest;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () =>
            reject(new Error('Could not read signature image.'));
        image.src = src;
    });
}
