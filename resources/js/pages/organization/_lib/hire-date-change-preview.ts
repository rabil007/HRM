export type HireDateChangePreview = {
    requires_acknowledgment: boolean;
    previous_hire_date: string | null;
    new_hire_date: string | null;
    annual_balance_years: number[];
};

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

export async function fetchHireDateChangePreview(
    employeeId: number,
    hireDate: string | null,
): Promise<HireDateChangePreview | null> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

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
        return null;
    }

    return (await response.json()) as HireDateChangePreview;
}
