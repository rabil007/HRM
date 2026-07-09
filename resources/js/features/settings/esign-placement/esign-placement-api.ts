import { destroy, update } from '@/routes/application/esign-placement';
import type { SignaturePlacementConfig } from '@/features/settings/esign-placement/esign-placement-coordinates';

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
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

type SavePayload = {
    page: number;
    canvas_width: number;
    canvas_height: number;
    signature: {
        left: number;
        top: number;
        width: number;
        height: number;
    };
    date: {
        left: number;
        top: number;
        width: number;
        height: number;
    };
};

export async function saveSignaturePlacement(
    documentType: string,
    payload: SavePayload,
): Promise<{ message: string; placement: SignaturePlacementConfig }> {
    const response = await fetch(update.url(documentType), {
        method: 'PUT',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(
            extractErrorMessage(data, 'Failed to save signature placement.'),
        );
    }

    return {
        message:
            (data as { message?: string } | null)?.message ??
            'Signature placement saved.',
        placement: (data as { placement: SignaturePlacementConfig }).placement,
    };
}

export async function resetSignaturePlacement(
    documentType: string,
): Promise<{ message: string; placement: SignaturePlacementConfig }> {
    const response = await fetch(destroy.url(documentType), {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(
            extractErrorMessage(data, 'Failed to reset signature placement.'),
        );
    }

    return {
        message:
            (data as { message?: string } | null)?.message ??
            'Signature placement reset to defaults.',
        placement: (data as { placement: SignaturePlacementConfig }).placement,
    };
}
