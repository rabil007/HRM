import type { PayrollCategory, PayrollPeriodStatus, WpsPreview } from '../types';

export type WpsPeriodOption = {
    id: number;
    name: string;
    start_date: string | null;
    end_date: string | null;
    status: PayrollPeriodStatus;
    status_label: string;
    payroll_category: PayrollCategory;
    payroll_category_label: string;
};

export type WpsExportPermissions = {
    export: boolean;
};

export type WpsExportPageProps = {
    periods: WpsPeriodOption[];
    selected_period_id: number | null;
    preview: WpsPreview | null;
    permissions: WpsExportPermissions;
};
