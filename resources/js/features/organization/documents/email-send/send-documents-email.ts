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

export async function sendDocumentsEmail(
    url: string,
    payload: Record<string, unknown>,
    errorFallback = 'Failed to send email. Please try again.',
): Promise<string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        body: JSON.stringify(payload),
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        throw new Error(extractErrorMessage(data, errorFallback));
    }

    return (
        (data as { message?: string } | null)?.message ??
        'Email sent successfully.'
    );
}
