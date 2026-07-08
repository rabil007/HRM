import type { ContractLifecycleFilter } from '@/features/organization/contracts/types';

export const LIFECYCLE_FILTER_LABELS: Record<
    Exclude<ContractLifecycleFilter, 'all'>,
    string
> = {
    active: 'Active',
    ending_30: 'Ending in 30 days',
    ending_60: 'Ending in 60 days',
    ending_90: 'Ending in 90 days',
    ended: 'Ended',
};

export const CONTRACT_STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
};

export const PAYROLL_CATEGORY_LABELS: Record<string, string> = {
    office: 'Office',
    crew: 'Crew',
};

export const SALARY_STRUCTURE_LABELS: Record<string, string> = {
    daily: 'Daily',
    monthly: 'Monthly',
};

export function formatContractStatus(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return CONTRACT_STATUS_LABELS[value] ?? value;
}

export function formatPayrollCategory(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return PAYROLL_CATEGORY_LABELS[value] ?? value;
}

export function formatSalaryStructure(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return SALARY_STRUCTURE_LABELS[value] ?? value;
}

export function formatContractMoney(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const numeric = Number(value);

    if (Number.isNaN(numeric)) {
        return String(value);
    }

    return numeric.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function contractMoneyValue(value: number | null | undefined): number {
    if (value === null || value === undefined) {
        return 0;
    }

    const numeric = Number(value);

    return Number.isNaN(numeric) ? 0 : numeric;
}

export function contractOfficeSalaryTotal(contract: {
    basic_salary: number | null;
    housing_allowance?: number | null;
    transport_allowance?: number | null;
    other_allowances?: number | null;
}): number {
    return (
        contractMoneyValue(contract.basic_salary)
        + contractMoneyValue(contract.housing_allowance)
        + contractMoneyValue(contract.transport_allowance)
        + contractMoneyValue(contract.other_allowances)
    );
}

export function contractCrewSalaryTotal(contract: {
    basic_salary: number | null;
    supplementary_allowance?: number | null;
    site_allowance?: number | null;
}): number {
    return (
        contractMoneyValue(contract.basic_salary)
        + contractMoneyValue(contract.supplementary_allowance)
        + contractMoneyValue(contract.site_allowance)
    );
}
