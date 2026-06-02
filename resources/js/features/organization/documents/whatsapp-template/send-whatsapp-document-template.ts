function extractErrorMessage(payload: unknown, fallback: string): string {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const data = payload as { message?: string; errors?: Record<string, string[]> };

    if (data.message && !data.errors) {
        return data.message;
    }

    const firstError = Object.values(data.errors ?? {})[0]?.[0];

    return firstError ?? data.message ?? fallback;
}

export async function sendWhatsAppDocumentTemplate(
    url: string,
    payload: { whatsapp_number: string; template_slug?: string },
    errorFallback = 'Failed to send document via WhatsApp.',
): Promise<{ message: string; message_id?: string | null }> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
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

    return (data as { message: string; message_id?: string | null }) ?? { message: 'Document sent via WhatsApp.' };
}
