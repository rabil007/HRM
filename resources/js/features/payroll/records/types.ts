export type PayrollRecordIndexItem = {
    id: number;
    payroll_category: 'office' | 'crew';
    payroll_category_label: string;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
    };
    period: {
        id: number;
        name: string;
        start_date: string | null;
        end_date: string | null;
    };
    gross_salary: string;
    net_salary: string;
    status: string;
};

export type PayrollRecordsFilters = {
    category: string;
    period_id: string;
    status: string;
    date_from: string;
    date_to: string;
};
