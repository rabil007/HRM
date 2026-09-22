export type LeaveTypeCategory = 'annual' | 'sick' | 'other';

export type LeaveTypePayrollTreatment = 'paid' | 'unpaid';

export type LeaveType = {
    id: number;
    name: string;
    code: string;
    days_per_year: string | number;
    carry_forward: boolean;
    max_carry_days: number;
    color: string | null;
    status: 'active' | 'inactive';
    payroll_treatment: LeaveTypePayrollTreatment;
    category: LeaveTypeCategory;
};

export type LeaveTypeFormData = {
    name: string;
    code: string;
    days_per_year: string;
    carry_forward: boolean;
    max_carry_days: string;
    color: string;
    status: 'active' | 'inactive';
    payroll_treatment: LeaveTypePayrollTreatment;
    category: LeaveTypeCategory;
};

export const defaultLeaveTypeFormData = (): LeaveTypeFormData => ({
    name: '',
    code: '',
    days_per_year: '0',
    carry_forward: false,
    max_carry_days: '0',
    color: '#3b82f6',
    status: 'active',
    payroll_treatment: 'paid',
    category: 'other',
});

export function leaveTypeToFormData(leaveType: LeaveType): LeaveTypeFormData {
    return {
        name: leaveType.name,
        code: leaveType.code,
        days_per_year: String(leaveType.days_per_year),
        carry_forward: leaveType.carry_forward,
        max_carry_days: String(leaveType.max_carry_days),
        color: leaveType.color ?? '#3b82f6',
        status: leaveType.status,
        payroll_treatment: leaveType.payroll_treatment ?? 'paid',
        category: leaveType.category ?? 'other',
    };
}
