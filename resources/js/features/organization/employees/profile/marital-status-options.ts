export const MARITAL_STATUS_OPTIONS = [
    { id: 1, label: 'Single', value: 'single' },
    { id: 2, label: 'Married', value: 'married' },
    { id: 3, label: 'Divorced', value: 'divorced' },
    { id: 4, label: 'Widowed', value: 'widowed' },
] as const;

export function maritalStatusLabel(value: string | null | undefined): string {
    return (
        MARITAL_STATUS_OPTIONS.find((option) => option.value === value)
            ?.label ??
        value ??
        '—'
    );
}
