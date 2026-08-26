import {
    buildEmployeeSmartSearchRequestBody,
    parseRetryAfterSeconds,
    parseSmartSearchResponse,
    SmartSearchMalformedResponseError,
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

function isAbortError(error: unknown): boolean {
    return (
        (error instanceof DOMException && error.name === 'AbortError') ||
        (error instanceof Error && error.name === 'AbortError')
    );
}

export class EmployeeSmartSearchRequestError extends Error {
    readonly status: number | null;

    readonly retryAfterSeconds: number | null;

    constructor(
        message: string,
        status: number | null = null,
        retryAfterSeconds: number | null = null,
    ) {
        super(message);
        this.name = 'EmployeeSmartSearchRequestError';
        this.status = status;
        this.retryAfterSeconds = retryAfterSeconds;
    }
}

export class EmployeeSmartSearchAbortedError extends Error {
    constructor() {
        super('Smart Search request was cancelled.');
        this.name = 'EmployeeSmartSearchAbortedError';
    }
}

export async function interpretEmployeeSmartSearch(
    prompt: string,
    signal?: AbortSignal,
): Promise<NormalizedSmartSearchResult> {
    if (signal?.aborted) {
        throw new EmployeeSmartSearchAbortedError();
    }

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
            signal,
            body: JSON.stringify(buildEmployeeSmartSearchRequestBody(prompt)),
        });
    } catch (error) {
        if (signal?.aborted || isAbortError(error)) {
            throw new EmployeeSmartSearchAbortedError();
        }

        throw new EmployeeSmartSearchRequestError(smartSearchErrorMessage(0));
    }

    if (signal?.aborted) {
        throw new EmployeeSmartSearchAbortedError();
    }

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        throw new EmployeeSmartSearchRequestError(
            smartSearchErrorMessage(response.status, data),
            response.status,
            parseRetryAfterSeconds(response.headers.get('Retry-After')),
        );
    }

    if (data === null) {
        throw new EmployeeSmartSearchRequestError(smartSearchErrorMessage(503));
    }

    try {
        return parseSmartSearchResponse(data);
    } catch (error) {
        if (error instanceof SmartSearchMalformedResponseError) {
            throw new EmployeeSmartSearchRequestError(
                smartSearchErrorMessage(503),
                503,
            );
        }

        throw error;
    }
}
