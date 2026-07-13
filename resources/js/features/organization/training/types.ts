import type {
    CountryOption,
    DepartmentTreeNode,
} from '@/features/organization/employees/types';
import type {
    CourseOption,
    TemplateFieldConfig,
    TrainingItem,
} from '@/pages/organization/employee-page.types';
import type { PaginationMeta } from '@/types/pagination';

export type TrainingExpiryStatus =
    | 'valid'
    | 'expiring_30'
    | 'expiring_15'
    | 'expiring_7'
    | 'expired';

export type TrainingExpiryFilter = 'all' | TrainingExpiryStatus;

export type TrainingExpirySummary = {
    total: number;
    expired: number;
    expiring_30: number;
    expiring_15: number;
    expiring_7: number;
};

export type TrainingListItem = {
    id: number;
    course_id: number | null;
    course_name: string | null;
    issue_date: string | null;
    expiry_date: string | null;
    expiry_status: TrainingExpiryStatus | null;
    expiry_remaining_days: number | null;
    expiry_label: string;
    institute_center: string | null;
    country_id: number | null;
    country_name: string | null;
    has_certificate: boolean;
    certificate_url: string | null;
    created_at: string | null;
    employee_id: number;
    employee_name: string;
    employee_no: string;
    employee_image: string | null;
    department_name: string | null;
    position_title: string | null;
};

export type TrainingEmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
};

export type TrainingBackNavigation = {
    href: string;
    label: string;
};

export type TrainingPageCan = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
    import: boolean;
};

export type TrainingsIndexProps = {
    summary: TrainingExpirySummary;
    expiry: TrainingExpiryFilter;
    search: string;
    branch_id: string;
    department_id: string;
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    trainings: TrainingListItem[];
    pagination: PaginationMeta;
    can: TrainingPageCan;
};

export type TrainingEmployeeBrowseProps = {
    employee: TrainingEmployeeSummary;
    trainings: TrainingItem[];
    courses: CourseOption[];
    countries: CountryOption[];
    template_fields: Record<string, TemplateFieldConfig> | null;
    back: TrainingBackNavigation;
    can: TrainingPageCan;
};

export type TrainingShowBackContext =
    | { from: 'employee-browse' }
    | { from: 'profile' }
    | {
          from: 'index';
          expiry?: string;
          search?: string;
          branch_id?: string;
          department_id?: string;
          page?: number;
      };

export type TrainingEmployeeBackContext = {
    from: 'index';
    search?: string;
    expiry?: string;
    branch_id?: string;
    department_id?: string;
    page?: number;
};
