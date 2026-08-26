import {
    buildEmployeeSmartSearchRequestBody,
    normalizeSmartSearchResponse,
    smartSearchErrorMessage,
} from '@/features/organization/employees/lib/employee-smart-search';
import type { NormalizedSmartSearchResult } from '@/features/organization/employees/lib/employee-smart-search';
import { interpret } from '@/routes/organization/employees/smart-search';

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export class EmployeeSmartSearchRequestError extends Error {
    readonly status: number | null;

    constructor(message: string, status: number | null = null) {
        super(message);
        this.name = 'EmployeeSmartSearchRequestError';
        this.status = status;
    }
}

export async function interpretEmployeeSmartSearch(
    prompt: string,
): Promise<NormalizedSmartSearchResult> {
    let response: Response;

    try {
        response = await fetch(interpret.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(buildEmployeeSmartSearchRequestBody(prompt)),
        });
    } catch {
        throw new EmployeeSmartSearchRequestError(smartSearchErrorMessage(0));
    }

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        throw new EmployeeSmartSearchRequestError(
            smartSearchErrorMessage(response.status, data),
            response.status,
        );
    }

    if (data === null) {
        throw new EmployeeSmartSearchRequestError(smartSearchErrorMessage(0));
    }

    return normalizeSmartSearchResponse(data);
}
