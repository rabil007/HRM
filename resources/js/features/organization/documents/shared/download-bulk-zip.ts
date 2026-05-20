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

export async function downloadBulkZip(
    url: string,
    payload: Record<string, unknown>,
    fallbackFilename = 'documents_export.zip',
): Promise<void> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/zip',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(
            response.status === 404
                ? 'No downloadable files found for the current selection.'
                : 'Download failed. Please try again.',
        );
    }

    const blob = await response.blob();
    const filename =
        parseContentDispositionFilename(response.headers.get('Content-Disposition')) ??
        fallbackFilename;

    triggerBrowserDownload(blob, filename);
}
