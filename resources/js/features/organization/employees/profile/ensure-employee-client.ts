export type EnsuredEmployee = {
    id: number;
    name: string;
    employee_no: string;
};

export type EnsureEmployeeRequestBody = {
    name: string;
    employee_profile_template_id: number | null;
    idempotency_key?: string | null;
};

export class EnsureEmployeeRequestError extends Error {
    readonly reason: 'name_required' | 'ensure_failed' | 'ensure_invalid';

    constructor(
        reason: EnsureEmployeeRequestError['reason'],
        message?: string,
    ) {
        super(message ?? reason);
        this.reason = reason;
    }
}

export type EnsureEmployeeFetch = (
    url: string,
    init: RequestInit,
) => Promise<Response>;

export async function postEnsureEmployee(
    body: EnsureEmployeeRequestBody,
    fetchImpl: EnsureEmployeeFetch = fetch,
): Promise<EnsuredEmployee> {
    const name = body.name.trim();

    if (name === '') {
        throw new EnsureEmployeeRequestError('name_required');
    }

    const token =
        typeof document !== 'undefined'
            ? (document
                  .querySelector('meta[name="csrf-token"]')
                  ?.getAttribute('content') ?? '')
            : '';

    const payload: Record<string, unknown> = {
        name,
        employee_profile_template_id: body.employee_profile_template_id,
    };

    const key = body.idempotency_key?.trim() ?? '';

    if (key !== '') {
        payload.idempotency_key = key;
    }

    const response = await fetchImpl('/organization/employees/ensure', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new EnsureEmployeeRequestError('ensure_failed');
    }

    const responsePayload = (await response.json()) as {
        employee?: EnsuredEmployee;
    };
    const ensured = responsePayload.employee;

    if (!ensured?.id) {
        throw new EnsureEmployeeRequestError('ensure_invalid');
    }

    return ensured;
}

export type DedupedEnsureRequest = {
    name: string;
    employee_profile_template_id: number | null;
    idempotency_key: string;
};

/**
 * Deduplicate concurrent ensure calls and reuse a resolved provisional employee.
 * Production hook uses this helper so retries share one in-flight request and
 * one server-scoped idempotency key.
 */
export function createDedupedEnsureEmployee(
    runRequest: (body: DedupedEnsureRequest) => Promise<EnsuredEmployee>,
): {
    ensure: (
        resolvedEmployeeId: number | null,
        body: DedupedEnsureRequest,
    ) => Promise<EnsuredEmployee>;
    reset: () => void;
} {
    let inFlight: Promise<EnsuredEmployee> | null = null;
    let cached: EnsuredEmployee | null = null;

    return {
        async ensure(
            resolvedEmployeeId: number | null,
            body: DedupedEnsureRequest,
        ): Promise<EnsuredEmployee> {
            if (resolvedEmployeeId !== null && resolvedEmployeeId > 0) {
                if (cached !== null && cached.id === resolvedEmployeeId) {
                    return cached;
                }

                return {
                    id: resolvedEmployeeId,
                    name: cached?.name ?? body.name,
                    employee_no: cached?.employee_no ?? '',
                };
            }

            if (cached !== null) {
                return cached;
            }

            if (inFlight !== null) {
                return inFlight;
            }

            inFlight = runRequest(body)
                .then((ensured) => {
                    cached = ensured;

                    return ensured;
                })
                .finally(() => {
                    inFlight = null;
                });

            return inFlight;
        },
        reset(): void {
            inFlight = null;
            cached = null;
        },
    };
}

export function createEnsureIdempotencyKey(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID().replaceAll('-', '');
    }

    return `ensure_${Date.now()}_${Math.random().toString(36).slice(2, 12)}`;
}
