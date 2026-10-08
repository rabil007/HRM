export type EnsuredEmployee = {
    id: number;
    name: string;
    employee_no: string;
};

export type EnsureEmployeeRequestBody = {
    name: string;
    employee_profile_template_id: number | null;
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
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    const response = await fetchImpl('/organization/employees/ensure', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            name,
            employee_profile_template_id: body.employee_profile_template_id,
        }),
    });

    if (!response.ok) {
        throw new EnsureEmployeeRequestError('ensure_failed');
    }

    const payload = (await response.json()) as {
        employee?: EnsuredEmployee;
    };
    const ensured = payload.employee;

    if (!ensured?.id) {
        throw new EnsureEmployeeRequestError('ensure_invalid');
    }

    return ensured;
}

/**
 * Deduplicate concurrent ensure calls and reuse a resolved provisional employee.
 */
export function createDedupedEnsureEmployee(
    runRequest: () => Promise<EnsuredEmployee>,
): {
    ensure: (resolvedEmployeeId: number | null) => Promise<EnsuredEmployee>;
    reset: () => void;
} {
    let inFlight: Promise<EnsuredEmployee> | null = null;
    let cached: EnsuredEmployee | null = null;

    return {
        async ensure(
            resolvedEmployeeId: number | null,
        ): Promise<EnsuredEmployee> {
            if (resolvedEmployeeId !== null && resolvedEmployeeId > 0) {
                if (cached !== null && cached.id === resolvedEmployeeId) {
                    return cached;
                }

                return {
                    id: resolvedEmployeeId,
                    name: cached?.name ?? '',
                    employee_no: cached?.employee_no ?? '',
                };
            }

            if (cached !== null) {
                return cached;
            }

            if (inFlight !== null) {
                return inFlight;
            }

            inFlight = runRequest()
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
