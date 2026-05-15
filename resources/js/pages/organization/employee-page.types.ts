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
    UserOption,
} from '@/features/organization/employees/types';

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
    cv_source?: string | null;
    emergency_contact?: string | null;
    emergency_phone?: string | null;
    emergency_contact_home_country?: string | null;
    emergency_phone_home_country?: string | null;
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
    spouse_birthdate?: string | null;
    dependent_children_count?: number | null;
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
    probation_end_date?: string | null;
    end_date?: string | null;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
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
    probation_end_date: string | null;
    labor_contract_id: string | null;
    status: string | null;
    basic_salary: number | null;
    housing_allowance: number | null;
    transport_allowance: number | null;
    other_allowances: number | null;
    created_at: string;
    updated_at: string;
};

export type EmployeeDocumentItem = {
    id: number;
    title: string | null;
    type: string | null;
    document_type_id: number | null;
    document_type: string | null;
    document_type_label: string | null;
    file_path: string;
    file_url: string;
    original_filename: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    current_version: number | null;
    can_preview: boolean;
    issue_date: string | null;
    expiry_date: string | null;
    document_number: string | null;
    notes: string | null;
    status: string | null;
    uploaded_by: string | null;
    created_at: string;
    versions: {
        id: number;
        version: number;
        file_url: string;
        original_filename: string | null;
        mime_type: string | null;
        size_bytes: number | null;
        replaced_by: string | null;
        created_at: string;
    }[];
};

export type DocumentTypeOption = {
    id: number;
    title: string;
};

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

export type EmployeeTab =
    | 'personal'
    | 'contract'
    | 'bank'
    | 'education'
    | 'work_experience'
    | 'vaccination'
    | 'documents';

export type EmployeePageProps = {
    employee: EmployeeDetails;
    contract: EmployeeContractDetails | null;
    documents: EmployeeDocumentItem[];
    education_qualifications: EducationQualificationItem[];
    work_experiences: WorkExperienceItem[];
    vaccinations: VaccinationItem[];
    document_types: DocumentTypeOption[];
    can: {
        documents_upload: boolean;
        documents_delete: boolean;
        education_manage: boolean;
        work_experience_manage: boolean;
        vaccination_manage: boolean;
    };
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    banks: BankOption[];
    ranks: RankOption[];
    recent_activity: ActivityItem[];
};
