import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

/**
 * All keys persisted via the employee profile header + personal tab inline editors.
 * Keep in sync with form.setData() usages in employee-header-card and employee-personal-tab.
 */
export const EMPLOYEE_PROFILE_FORM_KEYS = [
    'employee_no',
    'name',
    'branch_id',
    'department_id',
    'position_id',
    'rank_id',
    'manager_id',
    'personal_email',
    'work_email',
    'phone',
    'phone_home_country',
    'emergency_contact',
    'emergency_phone',
    'nearest_airport',
    'address',
    'date_of_birth',
    'place_of_birth',
    'gender_id',
    'religion_id',
    'visa_type_id',
    'company_visa_type_id',
    'nationality_id',
    'marital_status',
    'spouse_name',
    'passport_number',
    'emirates_id',
    'labor_card_number',
] as const;

export type EmployeeProfileFormKey = (typeof EMPLOYEE_PROFILE_FORM_KEYS)[number];

export type EmployeeProfileFormData = Record<EmployeeProfileFormKey, string> & {
    approval_location_ids: number[];
    sssa_option_ids: number[];
};

function sameIdSet(a: number[], b: number[]): boolean {
    if (a.length !== b.length) {
        return false;
    }

    const sortedA = [...a].sort((x, y) => x - y);
    const sortedB = [...b].sort((x, y) => x - y);

    return sortedA.every((value, index) => value === sortedB[index]);
}

export function buildEmployeeProfileFormInitial(
    employee: EmployeeDetails,
): EmployeeProfileFormData {
    return {
        employee_no: employee.employee_no ?? '',
        name: employee.name ?? '',
        branch_id: employee.branch?.id ? String(employee.branch.id) : '',
        department_id: employee.department?.id
            ? String(employee.department.id)
            : '',
        position_id: employee.position?.id ? String(employee.position.id) : '',
        rank_id: employee.rank_id ? String(employee.rank_id) : '',
        manager_id: employee.manager?.id ? String(employee.manager.id) : '',
        personal_email: employee.personal_email ?? employee.work_email ?? '',
        work_email: employee.work_email ?? '',
        phone: employee.phone ?? '',
        phone_home_country: employee.phone_home_country ?? '',
        emergency_contact: employee.emergency_contact ?? '',
        emergency_phone: employee.emergency_phone ?? '',
        nearest_airport: employee.nearest_airport ?? '',
        address: employee.address ?? '',
        date_of_birth: employee.date_of_birth ?? '',
        place_of_birth: employee.place_of_birth ?? '',
        gender_id: employee.gender_id ? String(employee.gender_id) : '',
        religion_id: employee.religion_id ? String(employee.religion_id) : '',
        visa_type_id: employee.visa_type_id ? String(employee.visa_type_id) : '',
        company_visa_type_id: employee.company_visa_type_id
            ? String(employee.company_visa_type_id)
            : '',
        nationality_id: employee.nationality_id
            ? String(employee.nationality_id)
            : '',
        marital_status: employee.marital_status ?? '',
        spouse_name: employee.spouse_name ?? '',
        passport_number: employee.passport_number ?? '',
        emirates_id: employee.emirates_id ?? '',
        labor_card_number: employee.labor_card_number ?? '',
        approval_location_ids: employee.approval_location_ids ?? [],
        sssa_option_ids: employee.sssa_option_ids ?? [],
    };
}

export function transformEmployeeProfileFormData(
    data: Record<string, unknown>,
): Record<string, unknown> {
    const approvalLocationIds = Array.isArray(data.approval_location_ids)
        ? data.approval_location_ids.map((id) => Number(id)).filter((id) => !Number.isNaN(id))
        : [];

    const sssaOptionIds = Array.isArray(data.sssa_option_ids)
        ? data.sssa_option_ids.map((id) => Number(id)).filter((id) => !Number.isNaN(id))
        : [];

    return {
        employee_no: String(data.employee_no ?? '').trim() || null,
        name: String(data.name ?? '').trim() || null,
        branch_id: data.branch_id ? Number(data.branch_id) : null,
        department_id: data.department_id ? Number(data.department_id) : null,
        position_id: data.position_id ? Number(data.position_id) : null,
        manager_id: data.manager_id ? Number(data.manager_id) : null,
        personal_email: String(data.personal_email ?? '').trim() || null,
        work_email: String(data.work_email ?? '').trim() || null,
        phone: String(data.phone ?? '').trim() || null,
        phone_home_country: String(data.phone_home_country ?? '').trim() || null,
        emergency_contact: String(data.emergency_contact ?? '').trim() || null,
        emergency_phone: String(data.emergency_phone ?? '').trim() || null,
        nearest_airport: String(data.nearest_airport ?? '').trim() || null,
        address: String(data.address ?? '').trim() || null,
        date_of_birth: data.date_of_birth || null,
        place_of_birth: String(data.place_of_birth ?? '').trim() || null,
        gender_id: data.gender_id ? Number(data.gender_id) : null,
        religion_id: data.religion_id ? Number(data.religion_id) : null,
        visa_type_id: data.visa_type_id ? Number(data.visa_type_id) : null,
        company_visa_type_id: data.company_visa_type_id
            ? Number(data.company_visa_type_id)
            : null,
        nationality_id: data.nationality_id ? Number(data.nationality_id) : null,
        marital_status: data.marital_status || null,
        spouse_name: String(data.spouse_name ?? '').trim() || null,
        passport_number: String(data.passport_number ?? '').trim() || null,
        emirates_id: String(data.emirates_id ?? '').trim() || null,
        labor_card_number: String(data.labor_card_number ?? '').trim() || null,
        approval_location_ids: approvalLocationIds,
        sssa_option_ids: sssaOptionIds,
    };
}

export function isEmployeeProfileFormDirty(
    current: EmployeeProfileFormData,
    initial: EmployeeProfileFormData,
): boolean {
    if (
        EMPLOYEE_PROFILE_FORM_KEYS.some(
            (key) => String(current[key] ?? '') !== String(initial[key] ?? ''),
        )
    ) {
        return true;
    }

    return (
        !sameIdSet(current.approval_location_ids, initial.approval_location_ids) ||
        !sameIdSet(current.sssa_option_ids, initial.sssa_option_ids)
    );
}
