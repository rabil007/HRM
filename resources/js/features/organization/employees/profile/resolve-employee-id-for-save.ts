export async function resolveEmployeeIdForSave(
    employeeId: number | null,
    ensureEmployee?: () => Promise<number>,
): Promise<number> {
    if (employeeId !== null && employeeId > 0) {
        return employeeId;
    }

    if (!ensureEmployee) {
        throw new Error('employee_id_required');
    }

    return ensureEmployee();
}
