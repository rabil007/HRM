export type HireDateChangePreview = {
    requires_acknowledgment: boolean;
    previous_hire_date: string | null;
    new_hire_date: string | null;
    annual_balance_years: number[];
};

export const HIRE_DATE_CHANGE_PREVIEW_ERROR_MESSAGE =
    'Unable to verify existing Annual Leave allocations. Please try again before saving the hire date change.';

export type HireDateChangePreviewFetchResult =
    | { status: 'requires_acknowledgment'; preview: HireDateChangePreview }
    | { status: 'continue'; preview: HireDateChangePreview }
    | { status: 'error' };

export function hireDateCalendarValueChanged(
    previous: string | null | undefined,
    next: string | null | undefined,
): boolean {
    const normalize = (value: string | null | undefined): string | null => {
        const trimmed = String(value ?? '').trim();

        return trimmed === '' ? null : trimmed;
    };

    return normalize(previous) !== normalize(next);
}

export function parseHireDateChangePreviewPayload(
    payload: unknown,
): HireDateChangePreview | null {
    if (payload === null || typeof payload !== 'object') {
        return null;
    }

    const record = payload as Record<string, unknown>;

    if (typeof record.requires_acknowledgment !== 'boolean') {
        return null;
    }

    const previousHireDate = record.previous_hire_date;
    const newHireDate = record.new_hire_date;

    if (
        previousHireDate !== null &&
        previousHireDate !== undefined &&
        typeof previousHireDate !== 'string'
    ) {
        return null;
    }

    if (
        newHireDate !== null &&
        newHireDate !== undefined &&
        typeof newHireDate !== 'string'
    ) {
        return null;
    }

    if (!Array.isArray(record.annual_balance_years)) {
        return null;
    }

    const annualBalanceYears = record.annual_balance_years.map((year) =>
        Number(year),
    );

    if (annualBalanceYears.some((year) => !Number.isFinite(year))) {
        return null;
    }

    return {
        requires_acknowledgment: record.requires_acknowledgment,
        previous_hire_date:
            typeof previousHireDate === 'string' ? previousHireDate : null,
        new_hire_date: typeof newHireDate === 'string' ? newHireDate : null,
        annual_balance_years: annualBalanceYears,
    };
}

export function resolveHireDateChangePreviewFetchResult(
    result: HireDateChangePreviewFetchResult,
): 'show_warning' | 'continue_save' | 'abort' {
    if (result.status === 'error') {
        return 'abort';
    }

    if (result.status === 'requires_acknowledgment') {
        return 'show_warning';
    }

    return 'continue_save';
}

export async function fetchHireDateChangePreview(
    employeeId: number,
    hireDate: string | null,
): Promise<HireDateChangePreviewFetchResult> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    try {
        const response = await fetch(
            `/organization/employees/${employeeId}/hire-date-change-preview`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                body: JSON.stringify({
                    hire_date: hireDate,
                }),
            },
        );

        if (!response.ok) {
            return { status: 'error' };
        }

        const payload = (await response.json()) as unknown;
        const preview = parseHireDateChangePreviewPayload(payload);

        if (preview === null) {
            return { status: 'error' };
        }

        if (preview.requires_acknowledgment) {
            return { status: 'requires_acknowledgment', preview };
        }

        return { status: 'continue', preview };
    } catch {
        return { status: 'error' };
    }
}
