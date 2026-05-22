import type { EmployeeForLinking } from '../types';

export function employeesAvailableForUser(
    employees: EmployeeForLinking[],
    userId: number | undefined,
): EmployeeForLinking[] {
    return employees.filter((employee) => employee.user_id === null || employee.user_id === userId);
}

export function formatEmployeeLinkLabel(employee: EmployeeForLinking): string {
    return `${employee.name} (${employee.employee_no})`;
}
