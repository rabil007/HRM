export type PayslipsFilters = {
    category: string;
    period_id: string;
    status: string;
    has_payslip: string;
};

export type PayslipListItem = {
    id: number;
    payroll_category: 'office' | 'crew';
    payroll_category_label: string;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
        work_email: string | null;
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
    payslip_path: string | null;
    has_payslip: boolean;
    wps_status: string | null;
    wps_status_label: string | null;
};
