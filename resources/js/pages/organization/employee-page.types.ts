import type {
    DocumentProfileItem,
    DocumentTypeOption,
} from '@/features/organization/documents/shared/types';
import type { SalaryPaymentMethodValue } from '@/features/organization/employees/salary-payment-method';
import type {
    ApprovalLocationOption,
    BankOption,
    BranchOption,
    CompanyVisaTypeOption,
    CountryOption,
    DepartmentOption,
    GenderOption,
    PositionOption,
    RankOption,
    ProjectOption,
    ReligionOption,
    SssaOption,
    VisaTypeOption,
} from '@/features/organization/employees/types';

export type { DocumentTypeOption };

export type TemplateFieldConfig = {
    visible: boolean;
    required: boolean;
};

export type ResolvedEmployeeTemplate = {
    version: number;
    tabs: Record<string, { visible: boolean }>;
    fields: Record<string, Record<string, TemplateFieldConfig>>;
    personal_field_keys: string[];
    employee_tabs: EmployeeProfileTabVisibility;
};

export type EmployeeCrewStatus = {
    deployment_id: number | null;
    status: string;
    label: string;
    hint?: string | null;
    current_vessel?: string | null;
    in_home_days?: number | null;
    vessel_name?: string | null;
};

export type EmployeeDetails = {
    id: number | null;
    user: {
        id: number;
        name: string | null;
        email: string | null;
        avatar: string | null;
    } | null;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    rank_id?: number | null;
    rank?: { id: number; name: string | null } | null;
    project_id?: number | null;
    project?: { id: number; title: string | null } | null;
    client_id?: number | null;
    client?: { id: number; name: string | null } | null;
    manager: {
        id: number;
        employee_no: string | null;
        name: string | null;
    } | null;
    bank?: { id: number; name: string | null } | null;
    employee_no: string;
    name: string;
    personal_email?: string | null;
    phone_home_country?: string | null;
    nearest_airport?: string | null;
    emergency_contact?: string | null;
    emergency_phone?: string | null;
    date_of_birth?: string | null;
    hire_date?: string | null;
    place_of_birth?: string | null;
    gender_id?: number | null;
    religion_id?: number | null;
    visa_type_id?: number | null;
    visa_type_ref?: {
        id: number;
        name: string | null;
    } | null;
    company_visa_type_id?: number | null;
    company_visa_type_ref?: {
        id: number;
        name: string | null;
    } | null;
    approval_location_ids?: number[];
    approval_locations?: { id: number; name: string }[];
    sssa_option_ids?: number[];
    sssa_options?: { id: number; name: string }[];
    nationality_id?: number | null;
    nationality_ref?: {
        id: number;
        name: string | null;
        code?: string | null;
    } | null;
    marital_status?: 'single' | 'married' | 'divorced' | 'widowed' | null;
    spouse_name?: string | null;
    labor_contract_id?: string | null;
    passport_number?: string | null;
    emirates_id?: string | null;
    bank_id?: number | null;
    iban?: string | null;
    account_name?: string | null;
    basic_salary?: number | null;
    housing_allowance?: number | null;
    transport_allowance?: number | null;
    other_allowances?: number | null;
    supplementary_allowance?: number | null;
    site_allowance?: number | null;
    work_email: string | null;
    phone: string | null;
    start_date?: string | null;
    end_date?: string | null;
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    crew_status?: EmployeeCrewStatus | null;
    salary_payment_method?: SalaryPaymentMethodValue;
    salary_payment_method_label?: string;
    employee_profile_template?: { id: number; name: string | null } | null;
    employee_profile_template_id?: number | null;
    address?: string | null;
    image?: string | null;
    created_at: string;
    updated_at: string;
};

export type ActivityItem = {
    id: number;
    event: string | null;
    description: string;
    causer: { id: number; name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
};

export type EmployeeContractDetails = {
    id: number;
    payroll_category: 'office' | 'crew' | null;
    salary_structure?: 'daily' | 'monthly' | null;
    start_date: string | null;
    end_date: string | null;
    labor_contract_id: string | null;
    status: string | null;
    basic_salary: number | null;
    housing_allowance: number | null;
    transport_allowance: number | null;
    other_allowances: number | null;
    supplementary_allowance: number | null;
    site_allowance: number | null;
    note: string | null;
    created_at: string;
    updated_at?: string;
    salary_revisions?: Array<{
        id: number;
        version: number;
        effective_from: string | null;
        reason: string | null;
        created_at: string | null;
        lines: Array<{
            component_code: string | null;
            component_name: string | null;
            rate_type: string | null;
            amount: number | string | null;
        }>;
    }>;
};

export type EmployeeDocumentItem = DocumentProfileItem;

export type EducationQualificationItem = {
    id: number;
    certificate: string;
    issue_date: string | null;
    university: string | null;
    country_id: number | null;
    country_name: string | null;
};

export type WorkExperienceItem = {
    id: number;
    company_name: string;
    job_title: string;
    date_from: string | null;
    date_to: string | null;
    responsibility: string | null;
    created_at: string;
};

export type VaccinationItem = {
    id: number;
    vaccination_name: string;
    country_id: number | null;
    country_name: string | null;
    first_dose_date: string | null;
    second_dose_date: string | null;
    booster_dose_date: string | null;
    created_at: string;
};

export type LanguageItem = {
    id: number;
    language_name: string;
    is_spoken: boolean;
    is_written: boolean;
    is_understood: boolean;
    is_mother_tongue: boolean;
    created_at: string;
};

export type EmployeeBankAccountItem = {
    id: number;
    bank_id: number | null;
    bank_name: string | null;
    iban: string | null;
    account_name: string | null;
    is_primary: boolean;
    created_at: string;
};

export type ClientOption = {
    id: number;
    name: string;
};

export type VesselTypeOption = {
    id: number;
    name: string;
};

export type VesselOption = {
    id: number;
    name: string;
    vessel_type_id: number;
    grt: string | null;
    bhp: number | null;
};

export type CourseOption = {
    id: number;
    name: string;
};

export type TrainingItem = {
    id: number;
    course_id: number | null;
    course_name: string | null;
    issue_date: string | null;
    expiry_date: string | null;
    institute_center: string | null;
    country_id: number | null;
    country_name: string | null;
    certificate_url: string | null;
    created_at: string;
};

export type SeaServiceItem = {
    id: number;
    vessel_type_id: number;
    vessel_type_name: string | null;
    vessel_id: number | null;
    vessel_name: string | null;
    rank_id: number;
    rank_name: string | null;
    start_date: string | null;
    end_date: string | null;
    total_months: number;
    total_days: number;
    grt: string | null;
    bhp: number | null;
    client_id: number | null;
    client_name: string | null;
    is_offshore: boolean;
    created_at: string;
};

export type EmployeeTab =
    | 'personal'
    | 'contract'
    | 'bank'
    | 'education'
    | 'work_experience'
    | 'vaccination'
    | 'languages'
    | 'training'
    | 'sea_service'
    | 'documents';

export type EmployeeNavigation = {
    position: number;
    total: number;
    previous_id: number | null;
    next_id: number | null;
    list_query: Record<string, string>;
};

export type EmployeeProfileTabVisibility = {
    personal: boolean;
    contract: boolean;
    bank: boolean;
    education?: boolean;
    work_experience?: boolean;
    languages?: boolean;
    documents: boolean;
    sea_service: boolean;
    vaccination: boolean;
    training: boolean;
    /** null = no template assigned, show all fields; string[] = only these field keys are enabled */
    profile_fields: string[] | null;
    template_fields?: Record<string, Record<string, TemplateFieldConfig>>;
};

export type ProfileTemplateOption = {
    id: number;
    name: string;
    description: string | null;
};

export type EmployeePageProps = {
    mode?: 'edit' | 'create';
    employee_navigation?: EmployeeNavigation | null;
    resolved_template?: ResolvedEmployeeTemplate;
    profile_templates?: ProfileTemplateOption[];
    selected_profile_template_id?: number | null;
    employee: EmployeeDetails;
    contract_count?: number;
    contracts?: EmployeeContractDetails[];
    documents?: EmployeeDocumentItem[];
    education_qualifications?: EducationQualificationItem[];
    work_experiences?: WorkExperienceItem[];
    vaccinations?: VaccinationItem[];
    languages?: LanguageItem[];
    bank_accounts?: EmployeeBankAccountItem[];
    sea_services?: SeaServiceItem[];
    trainings?: TrainingItem[];
    courses?: CourseOption[];
    document_types?: DocumentTypeOption[];
    roles?: { id: number; name: string }[];
    can: {
        create_user: boolean;
        assign_profile_template?: boolean;
        documents_view: boolean;
        documents_download: boolean;
        documents_upload: boolean;
        documents_delete: boolean;
        education_manage: boolean;
        contracts_view: boolean;
        contracts_create: boolean;
        contracts_update: boolean;
        contracts_delete: boolean;
        work_experience_manage: boolean;
        vaccination_manage: boolean;
        languages_manage: boolean;
        bank_accounts_view?: boolean;
        bank_accounts_create?: boolean;
        bank_accounts_update?: boolean;
        bank_accounts_delete?: boolean;
        bank_accounts_manage: boolean;
        sea_service_manage: boolean;
        training_view: boolean;
        training_create: boolean;
        training_update: boolean;
        training_delete: boolean;
        training_import: boolean;
        deployments_view: boolean;
    };
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    visa_types: VisaTypeOption[];
    company_visa_types: CompanyVisaTypeOption[];
    approval_locations: ApprovalLocationOption[];
    sssa_options: SssaOption[];
    banks: BankOption[];
    ranks: RankOption[];
    projects: ProjectOption[];
    profile_clients: ClientOption[];
    vessel_types?: VesselTypeOption[];
    vessels?: VesselOption[];
    clients?: ClientOption[];
    employee_tabs: EmployeeProfileTabVisibility;
};
