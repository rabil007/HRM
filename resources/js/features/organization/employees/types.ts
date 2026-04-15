export type BranchOption = {
    id: number;
    name: string | null;
};

export type DepartmentOption = {
    id: number;
    name: string | null;
};

export type PositionOption = {
    id: number;
    department_id: number | null;
    title: string | null;
};

export type ManagerOption = {
    id: number;
    employee_no: string;
    first_name: string;
    last_name: string;
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type VisaTypeOption = {
    id: number;
    name: string;
};

export type ReligionOption = {
    id: number;
    name: string;
};

export type GenderOption = {
    id: number;
    name: string;
};

export type BankOption = {
    id: number;
    name: string;
};

export type CountryOption = {
    id: number;
    name: string;
    code: string;
};

export type Employee = {
    id: number;
    user_id: number | null;
    branch_id: number | null;
    department_id: number | null;
    position_id: number | null;
    manager_id: number | null;
    employee_no: string;
    first_name: string;
    last_name: string;
    name: string;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    work_email: string | null;
    personal_email?: string | null;
    phone: string | null;
    phone_home_country?: string | null;
    nearest_airport?: string | null;
    cv_source?: string | null;
    emergency_contact?: string | null;
    emergency_phone?: string | null;
    emergency_contact_home_country?: string | null;
    emergency_phone_home_country?: string | null;
    date_of_birth?: string | null;
    place_of_birth?: string | null;
    gender?: 'male' | 'female' | 'other' | null;
    gender_id?: number | null;
    gender_ref?: { id: number; name: string | null } | null;
    religion?: string | null;
    religion_id?: number | null;
    religion_ref?: { id: number; name: string | null } | null;
    nationality?: string | null;
    marital_status?: 'single' | 'married' | 'divorced' | 'widowed' | null;
    spouse_name?: string | null;
    spouse_birthdate?: string | null;
    dependent_children_count?: number | null;
    labor_contract_id?: string | null;
    visa_type?: string | null;
    visa_type_id?: number | null;
    visa_type_ref?: { id: number; name: string | null } | null;
    passport_number?: string | null;
    passport_issued_at?: string | null;
    passport_expiry?: string | null;
    emirates_id?: string | null;
    bank_id?: number | null;
    bank?: { id: number; name: string | null } | null;
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    hire_date: string;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    created_at: string;
};

export type EmployeeFormData = {
    user_id: number | '';
    branch_id: number | '';
    department_id: number | '';
    position_id: number | '';
    manager_id: number | '';
    employee_no: string;
    first_name: string;
    last_name: string;
    work_email: string;
    personal_email: string;
    phone: string;
    phone_home_country: string;
    nearest_airport: string;
    cv_source: string;
    emergency_contact: string;
    emergency_phone: string;
    emergency_contact_home_country: string;
    emergency_phone_home_country: string;
    date_of_birth: string;
    place_of_birth: string;
    gender_id: number | '';
    religion_id: number | '';
    nationality: string;
    marital_status: 'single' | 'married' | 'divorced' | 'widowed' | '';
    spouse_name: string;
    spouse_birthdate: string;
    dependent_children_count: number | '';
    visa_type_id: number | '';
    labor_contract_id: string;
    passport_number: string;
    passport_issued_at: string;
    passport_expiry: string;
    emirates_id: string;
    bank_id: number | '';
    hire_date: string;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
};

