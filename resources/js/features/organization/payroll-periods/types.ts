export type PayrollPeriod = {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    payment_date: string;
    status: string;
    status_label: string;
    notes: string | null;
    is_editable: boolean;
    created_at: string | null;
};

export type PayrollPeriodFormData = {
    name: string;
    start_date: string;
    end_date: string;
    payment_date: string;
    notes: string;
};

export type PayrollPeriodPermissions = {
    create: boolean;
};
