import type {
    FolderShareLinksResponse,
    ShareLinksResponse,
} from '@/features/organization/documents/whatsapp-share/types';

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

async function postJson<T>(
    url: string,
    body: Record<string, unknown>,
    errorFallback: string,
): Promise<T> {
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
        body: JSON.stringify(body),
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        throw new Error(extractErrorMessage(data, errorFallback));
    }

    return data as T;
}

export async function fetchDocumentShareLinks(
    url: string,
    documentIds: number[],
    password?: string,
    expiresAt?: string,
    errorFallback = 'Failed to generate share links. Please try again.',
): Promise<ShareLinksResponse> {
    return postJson<ShareLinksResponse>(
        url,
        {
            document_ids: documentIds,
            password: password || null,
            expires_at: expiresAt || null,
        },
        errorFallback,
    );
}

export async function fetchFolderShareLinks(
    url: string,
    employeeIds: number[],
    options: {
        password?: string;
        expiresAt?: string;
        canDownload?: boolean;
        canUpload?: boolean;
    } = {},
    errorFallback = 'Failed to generate folder share links. Please try again.',
): Promise<FolderShareLinksResponse> {
    return postJson<FolderShareLinksResponse>(
        url,
        {
            employee_ids: employeeIds,
            password: options.password || null,
            expires_at: options.expiresAt || null,
            can_download: options.canDownload ?? true,
            can_upload: options.canUpload ?? false,
        },
        errorFallback,
    );
}
