import type { WhatsAppDocumentsSendResponse } from '@/features/organization/documents/whatsapp-send/types';

function extractErrorMessage(payload: unknown, fallback: string): string {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const data = payload as {
        message?: string;
        errors?: Record<string, string[]>;
        results?: Array<{ message?: string; error?: string }>;
    };

    if (data.message && data.errors) {
        const firstError = Object.values(data.errors)[0]?.[0];

        return firstError ?? data.message;
    }

    if (data.message) {
        return data.message;
    }

    const firstError = Object.values(data.errors ?? {})[0]?.[0];

    return firstError ?? fallback;
}

export async function sendDocumentsWhatsApp(
    url: string,
    payload: Record<string, unknown>,
    errorFallback = 'Failed to send documents via WhatsApp.',
): Promise<WhatsAppDocumentsSendResponse> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

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

    if (!response.ok || !data || typeof data !== 'object') {
        throw new Error(extractErrorMessage(data, errorFallback));
    }

    return data as WhatsAppDocumentsSendResponse;
}
