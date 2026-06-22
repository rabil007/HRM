import type { PayrollPeriod } from '@/features/organization/payroll-periods/types';

export type CrewTimesheet = {
    id: number;
    period_id: number;
    employee_id: number;
    standby_from: string | null;
    standby_to: string | null;
    standby_days: string | null;
    onsite_from: string | null;
    onsite_to: string | null;
    onsite_days: string | null;
    overtime_amount: string;
    additional_amount: string;
    deduction_amount: string;
    remarks: string | null;
};

export type CrewPayrollRow = {
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
    };
    period_id: number;
    timesheet: CrewTimesheet | null;
    is_filled: boolean;
};

export type CrewTimesheetFormData = {
    period_id: number;
    employee_id: number;
    standby_from: string;
    standby_to: string;
    standby_days: string;
    onsite_from: string;
    onsite_to: string;
    onsite_days: string;
    overtime_amount: string;
    additional_amount: string;
    deduction_amount: string;
    remarks: string;
};

export type CrewPayrollPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
};

export type CrewPayrollBoardProps = {
    periods: PayrollPeriod[];
    selectedPeriod: PayrollPeriod | null;
    rows: CrewPayrollRow[];
    pagination: import('@/types/pagination').PaginationMeta;
    search: string;
    permissions: CrewPayrollPermissions;
};

function formatAmount(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function formatTimesheetAmount(value: string | null | undefined): string {
    return formatAmount(value);
}

export function formatTimesheetDays(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}
