export type BranchOption = {
    id: number;
    name: string | null;
};

export type DepartmentOption = {
    id: number;
    name: string | null;
};

export type DepartmentTreePositionNode = {
    id: number;
    name: string;
    count: number;
};

export type DepartmentTreeNode = {
    id: number | null;
    name: string;
    count: number;
    children: DepartmentTreeNode[];
    positions: DepartmentTreePositionNode[];
};

export type PositionOption = {
    id: number;
    department_id: number | null;
    title: string | null;
};

export type ManagerOption = {
    id: number;
    employee_no: string;
    name: string;
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type ReligionOption = {
    id: number;
    name: string;
};

export type GenderOption = {
    id: number;
    name: string;
};

export type VisaTypeOption = {
    id: number;
    name: string;
};

export type CompanyVisaTypeOption = {
    id: number;
    name: string;
};

export type ApprovalLocationOption = {
    id: number;
    name: string;
};

export type SssaOption = {
    id: number;
    name: string;
};

export type RankOption = {
    id: number;
    name: string;
};

export type ProjectOption = {
    id: number;
    title: string;
};

export type BankOption = {
    id: number;
    name: string;
};

export type RoleOption = {
    id: number;
    name: string;
};

export type CountryOption = {
    id: number;
    name: string;
    code: string;
    dial_code: string | null;
};

export type Employee = {
    id: number;
    user_id: number | null;
    branch_id: number | null;
    department_id: number | null;
    position_id: number | null;
    employee_no: string;
    image?: string | null;
    name: string;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    work_email: string | null;
    personal_email?: string | null;
    phone: string | null;
    phone_home_country?: string | null;
    nearest_airport?: string | null;
    emergency_contact?: string | null;
    emergency_phone?: string | null;
    date_of_birth?: string | null;
    hire_date?: string | null;
    place_of_birth?: string | null;
    gender_id?: number | null;
    gender_ref?: { id: number; name: string | null } | null;
    religion_id?: number | null;
    religion_ref?: { id: number; name: string | null } | null;
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
    bank?: { id: number; name: string | null } | null;
    basic_salary?: number | null;
    housing_allowance?: number | null;
    transport_allowance?: number | null;
    other_allowances?: number | null;
    supplementary_allowance?: number | null;
    site_allowance?: number | null;
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    crew_status?: {
        deployment_id: number | null;
        status: string;
        label: string;
        hint?: string | null;
        current_vessel?: string | null;
        in_home_days?: number | null;
        vessel_name?: string | null;
    } | null;
    start_date?: string | null;
    end_date?: string | null;
    created_at: string;
};

export type EmployeeFormData = {
    user_id: number | '';
    branch_id: number | '';
    department_id: number | '';
    position_id: number | '';
    employee_no: string;
    name: string;
    image: File | null;
    work_email: string;
    personal_email: string;
    phone: string;
    phone_home_country: string;
    nearest_airport: string;
    emergency_contact: string;
    emergency_phone: string;
    date_of_birth: string;
    place_of_birth: string;
    gender_id: number | '';
    religion_id: number | '';
    nationality_id: number | '';
    marital_status: 'single' | 'married' | 'divorced' | 'widowed' | '';
    spouse_name: string;
    passport_number: string;
    emirates_id: string;
    bank_id: number | '';
    iban: string;
    basic_salary: string;
    housing_allowance: string;
    transport_allowance: string;
    other_allowances: string;
    supplementary_allowance: string;
    site_allowance: string;
    start_date: string;
    end_date: string;
    labor_contract_id: string;
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
};
