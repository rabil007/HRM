import type { RecentActivityItem } from '@/components/recent-activity-card';
import type {
    DepartmentTreeNode,
    RankOption,
} from '@/features/organization/employees/types';
import type {
    ClientOption,
    SeaServiceItem,
    TemplateFieldConfig,
    VesselOption,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';
import type { PaginationMeta } from '@/types/pagination';

export type SeaServiceSummary = {
    total: number;
    active: number;
};

export type SeaServiceListItem = {
    id: number;
    employee_id: number;
    employee_name: string;
    employee_no: string;
    employee_image: string | null;
    department_name: string | null;
    position_title: string | null;
    vessel_type_id: number | null;
    vessel_type_name: string | null;
    vessel_id: number | null;
    vessel_name: string | null;
    rank_id: number | null;
    rank_name: string | null;
    client_id: number | null;
    client_name: string | null;
    start_date: string | null;
    end_date: string | null;
    total_months: number;
    total_days: number;
    crew_assignment_phase_id: number | null;
    has_assignment_phase: boolean;
    sort_order: number;
    total_sea_services: number;
};

export type SeaServiceEmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
};

export type SeaServiceBackNavigation = {
    href: string;
    label: string;
};

export type SeaServicePageCan = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
    import: boolean;
};

export type SeaServicesIndexProps = {
    summary: SeaServiceSummary;
    search: string;
    vessel_id: string;
    vessel_type_id: string;
    rank_id: string;
    client_id: string;
    active: string;
    start_date: string;
    end_date: string;
    branch_id: string;
    department_id: string;
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    sea_services: SeaServiceListItem[];
    pagination: PaginationMeta;
    vessel_types: VesselTypeOption[];
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    can: SeaServicePageCan;
};

export type SeaServiceEmployeeBrowseProps = {
    employee: SeaServiceEmployeeSummary;
    sea_services: SeaServiceItem[];
    vessel_types: VesselTypeOption[];
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    template_fields: Record<string, TemplateFieldConfig> | null;
    back: SeaServiceBackNavigation;
    can: SeaServicePageCan;
};

export type SeaServiceShowProps = {
    sea_service: SeaServiceListItem;
    employee: SeaServiceEmployeeSummary;
    vessel_types: VesselTypeOption[];
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    template_fields: Record<string, TemplateFieldConfig> | null;
    can: SeaServicePageCan;
    back: SeaServiceBackNavigation;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

export type SeaServiceEmployeeBackContext = {
    from: 'index';
    search?: string;
    vessel_id?: string;
    vessel_type_id?: string;
    rank_id?: string;
    client_id?: string;
    active?: string;
    start_date?: string;
    end_date?: string;
    branch_id?: string;
    department_id?: string;
    page?: number;
};

export type SeaServiceShowBackContext =
    | { from: 'employee-browse' }
    | { from: 'profile' }
    | ({ from: 'index' } & Omit<SeaServiceEmployeeBackContext, 'from'>);
