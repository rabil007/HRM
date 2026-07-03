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
    draft: 'Draft',
};

export const CONTRACT_TYPE_LABELS: Record<string, string> = {
    limited: 'Limited',
    unlimited: 'Unlimited',
    part_time: 'Part time',
    contract: 'Contract',
};

export const CONTRACT_STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
    draft: 'Draft',
};

export const PAYROLL_CATEGORY_LABELS: Record<string, string> = {
    office: 'Office',
    crew: 'Crew',
};

export function formatContractType(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return CONTRACT_TYPE_LABELS[value] ?? value;
}

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
