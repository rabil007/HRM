import type { AppVersionResponse } from '@/lib/app-refresh/types';

export async function fetchAppVersion(
    clientVersion: string,
    url: string,
): Promise<AppVersionResponse> {
    const separator = url.includes('?') ? '&' : '?';
    const endpoint = `${url}${separator}client_version=${encodeURIComponent(clientVersion)}`;

    const response = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        cache: 'no-store',
    });

    if (!response.ok) {
        throw new Error(`Version check failed (${response.status})`);
    }

    return (await response.json()) as AppVersionResponse;
}
