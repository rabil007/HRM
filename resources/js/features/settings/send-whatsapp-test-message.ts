function extractErrorMessage(payload: unknown, fallback: string): string {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const data = payload as { message?: string; errors?: Record<string, string[]> };

    if (data.message) {
        return data.message;
    }

    const firstError = Object.values(data.errors ?? {})[0]?.[0];

    return firstError ?? fallback;
}

export type WhatsAppApiExchange = {
    request: {
        method: string;
        url: string;
        payload: Record<string, unknown>;
    };
    response: {
        http_status: number;
        body: Record<string, unknown>;
    };
};

export type WhatsAppTestSendResult = {
    success: boolean;
    message: string;
    message_id: string | null;
    http_status: number | null;
    media_id?: string | null;
    normalized_phone?: string | null;
    delivery_note?: string | null;
    data: Record<string, unknown> | null;
    api?: WhatsAppApiExchange | null;
    media_api?: WhatsAppApiExchange | null;
};

export async function sendWhatsAppTestText(
    phone: string,
    message: string,
): Promise<WhatsAppTestSendResult> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    const response = await fetch('/settings/integrations/whatsapp/send-test-text', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        body: JSON.stringify({ phone, message }),
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (data && typeof data === 'object') {
        return data as WhatsAppTestSendResult;
    }

    throw new Error(extractErrorMessage(data, 'Failed to send WhatsApp test message.'));
}

export async function sendWhatsAppTestDocument(
    phone: string,
    file: File,
    caption?: string,
): Promise<WhatsAppTestSendResult> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const formData = new FormData();
    formData.append('phone', phone);
    formData.append('file', file);

    if (caption?.trim()) {
        formData.append('caption', caption.trim());
    }

    const response = await fetch('/settings/integrations/whatsapp/send-test-document', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        body: formData,
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (data && typeof data === 'object') {
        return data as WhatsAppTestSendResult;
    }

    throw new Error(extractErrorMessage(data, 'Failed to send WhatsApp test document.'));
}

export async function sendWhatsAppTestTemplate(phone: string): Promise<WhatsAppTestSendResult> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    const response = await fetch('/settings/integrations/whatsapp/send-test-template', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        body: JSON.stringify({ phone }),
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (data && typeof data === 'object') {
        return data as WhatsAppTestSendResult;
    }

    throw new Error(extractErrorMessage(data, 'Failed to send WhatsApp test template.'));
}
