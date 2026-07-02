function parseContentDispositionFilename(header: string | null): string | null {
    if (!header) {
        return null;
    }

    const utfMatch = header.match(/filename\*=UTF-8''([^;]+)/i);

    if (utfMatch?.[1]) {
        return decodeURIComponent(utfMatch[1]);
    }

    const match = header.match(/filename="?([^";]+)"?/i);

    return match?.[1] ?? null;
}

function triggerBrowserDownload(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

function extractErrorMessage(payload: unknown, fallback: string): string {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const data = payload as {
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (data.message) {
        return data.message;
    }

    const firstError = Object.values(data.errors ?? {})[0]?.[0];

    return firstError ?? fallback;
}

export async function downloadBinaryExport(
    url: string,
    payload: Record<string, unknown>,
    accept: string,
    fallbackFilename: string,
    errorFallback = 'Download failed. Please try again.',
): Promise<void> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: accept,
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        const contentType = response.headers.get('Content-Type') ?? '';

        if (contentType.includes('application/json')) {
            const payload = await response.json().catch(() => null);

            throw new Error(extractErrorMessage(payload, errorFallback));
        }

        throw new Error(
            response.status === 404
                ? 'No downloadable files found for the current selection.'
                : errorFallback,
        );
    }

    const blob = await response.blob();
    const filename =
        parseContentDispositionFilename(
            response.headers.get('Content-Disposition'),
        ) ?? fallbackFilename;

    triggerBrowserDownload(blob, filename);
}
