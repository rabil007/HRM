export type SalaryAdjustmentStatus = 'pending' | 'approved' | 'rejected' | 'applied';

export type SalaryAdjustmentType =
    | 'bonus'
    | 'commission'
    | 'deduction'
    | 'loan'
    | 'advance'
    | 'other';

export type SalaryAdjustmentEmployeeOption = {
    id: number;
    employee_no: string | null;
    name: string;
};

export type SalaryAdjustmentPeriodOption = {
    id: number;
    name: string;
    start_date: string | null;
    end_date: string | null;
};

export type SalaryAdjustment = {
    id: number;
    employee: SalaryAdjustmentEmployeeOption | null;
    period: { id: number; name: string } | null;
    type: SalaryAdjustmentType;
    type_label: string;
    amount: string;
    reason: string;
    rejection_reason: string | null;
    status: SalaryAdjustmentStatus;
    status_label: string;
    approved_at: string | null;
    approver: { id: number; name: string } | null;
    created_at: string | null;
};

export type SalaryAdjustmentFormData = {
    employee_id: number | '';
    period_id: number | '';
    type: SalaryAdjustmentType | '';
    amount: string;
    reason: string;
};

export type SalaryAdjustmentFilters = {
    status: '' | SalaryAdjustmentStatus;
    type: '' | SalaryAdjustmentType;
    employee_id: string;
};

export type SalaryAdjustmentPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    approve: boolean;
};

export const defaultSalaryAdjustmentFormData = (): SalaryAdjustmentFormData => ({
    employee_id: '',
    period_id: '',
    type: '',
    amount: '',
    reason: '',
});

export function salaryAdjustmentToFormData(adjustment: SalaryAdjustment): SalaryAdjustmentFormData {
    return {
        employee_id: adjustment.employee?.id ?? '',
        period_id: adjustment.period?.id ?? '',
        type: adjustment.type,
        amount: adjustment.amount,
        reason: adjustment.reason,
    };
}
