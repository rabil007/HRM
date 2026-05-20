import type { DocumentProfileItem, DocumentTypeOption } from '@/features/organization/documents/shared/types';
import type {
    BankOption,
    BranchOption,
    CountryOption,
    DepartmentOption,
    GenderOption,
    ManagerOption,
    PositionOption,
    RankOption,
    ReligionOption,
} from '@/features/organization/employees/types';

export type { DocumentTypeOption };

export type EmployeeDetails = {
    id: number;
    user: { id: number; name: string | null; email: string | null } | null;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    rank_id?: number | null;
    rank?: { id: number; name: string | null } | null;
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
    place_of_birth?: string | null;
    gender_id?: number | null;
    religion_id?: number | null;
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
    labor_card_number?: string | null;
    bank_id?: number | null;
    iban?: string | null;
    account_name?: string | null;
    basic_salary?: number | null;
    housing_allowance?: number | null;
    transport_allowance?: number | null;
    other_allowances?: number | null;
    work_email: string | null;
    phone: string | null;
    start_date?: string | null;
    end_date?: string | null;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    onboarding_template?: { id: number; name: string | null } | null;
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
    contract_type: string | null;
    start_date: string | null;
    end_date: string | null;
    labor_contract_id: string | null;
    status: string | null;
    basic_salary: number | null;
    housing_allowance: number | null;
    transport_allowance: number | null;
    other_allowances: number | null;
    created_at: string;
    updated_at: string;
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

export type SeaServiceItem = {
    id: number;
    vessel_type_id: number;
    vessel_type_name: string | null;
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
    documents: boolean;
    sea_service: boolean;
    vaccination: boolean;
    /** null = no template assigned, show all fields; string[] = only these field keys are enabled */
    profile_fields: string[] | null;
};

export type EmployeePageProps = {
    employee_navigation: EmployeeNavigation | null;
    employee: EmployeeDetails;
    contracts?: EmployeeContractDetails[];
    documents?: EmployeeDocumentItem[];
    education_qualifications?: EducationQualificationItem[];
    work_experiences?: WorkExperienceItem[];
    vaccinations?: VaccinationItem[];
    languages?: LanguageItem[];
    bank_accounts?: EmployeeBankAccountItem[];
    sea_services?: SeaServiceItem[];
    document_types?: DocumentTypeOption[];
    can: {
        documents_upload: boolean;
        documents_delete: boolean;
        education_manage: boolean;
        contracts_manage: boolean;
        work_experience_manage: boolean;
        vaccination_manage: boolean;
        languages_manage: boolean;
        bank_accounts_manage: boolean;
        sea_service_manage: boolean;
    };
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    banks: BankOption[];
    ranks: RankOption[];
    vessel_types?: VesselTypeOption[];
    clients?: ClientOption[];
    employee_tabs: EmployeeProfileTabVisibility;
};
