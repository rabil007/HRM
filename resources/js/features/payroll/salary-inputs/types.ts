export type SalaryInputTypeRecord = {
    id: number;
    name: string;
    code: string;
    is_addition: boolean;
    status: 'active' | 'inactive';
    salary_inputs_count: number;
};

export type SalaryInputTypeFormData = {
    name: string;
    code: string;
    is_addition: boolean;
    status: 'active' | 'inactive';
};

export const defaultSalaryInputTypeFormData = (): SalaryInputTypeFormData => ({
    name: '',
    code: '',
    is_addition: false,
    status: 'active',
});

export function salaryInputTypeToFormData(
    type: SalaryInputTypeRecord,
): SalaryInputTypeFormData {
    return {
        name: type.name,
        code: type.code,
        is_addition: type.is_addition,
        status: type.status,
    };
}
