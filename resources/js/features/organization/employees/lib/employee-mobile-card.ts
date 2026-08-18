import type { Employee, EmployeePageCan } from '../types';

export type EmployeeMobileCardModel = {
    title: string;
    subtitle: string;
    assignmentLine: string;
    status: Employee['status'];
    attention: string | null;
    showEdit: boolean;
    showDelete: boolean;
};

const SENSITIVE_EMPLOYEE_FIELDS = [
    'work_email',
    'personal_email',
    'phone',
    'phone_home_country',
    'emergency_contact',
    'emergency_phone',
    'date_of_birth',
    'place_of_birth',
    'spouse_name',
    'passport_number',
    'emirates_id',
    'iban',
    'basic_salary',
    'housing_allowance',
    'transport_allowance',
] as const;

export function employeeMobileCardOmitsSensitiveFields(
    model: EmployeeMobileCardModel,
): boolean {
    const serialized = JSON.stringify(model);

    return SENSITIVE_EMPLOYEE_FIELDS.every(
        (field) => !serialized.includes(`"${field}"`),
    );
}

export function employeeMobileCardModel(
    employee: Employee,
    can: Pick<EmployeePageCan, 'update' | 'delete'>,
): EmployeeMobileCardModel {
    const assignmentLine = [employee.department?.name, employee.position?.title]
        .map((value) => value?.trim())
        .filter((value): value is string => Boolean(value))
        .join(' · ');

    return {
        title: employee.name,
        subtitle: employee.employee_no,
        assignmentLine,
        status: employee.status,
        attention: employeeAttention(employee),
        showEdit: can.update,
        showDelete: can.delete,
    };
}

function employeeAttention(employee: Employee): string | null {
    const crewStatus = employee.crew_status;

    if (!crewStatus) {
        return null;
    }

    const warning = crewStatus.warning?.trim();

    if (warning) {
        return warning;
    }

    const vessel = (
        crewStatus.current_vessel ?? crewStatus.vessel_name
    )?.trim();

    if (vessel) {
        return crewStatus.label ? `${crewStatus.label} · ${vessel}` : vessel;
    }

    return null;
}
