import type { EmployeeTab } from '@/pages/organization/employee-page.types';

/**
 * Inertia optional props required to render each employee profile tab.
 * Personal uses only the initial page payload.
 */
export const EMPLOYEE_PROFILE_TAB_PROPS: Record<EmployeeTab, string[]> = {
    personal: [],
    contract: ['contracts'],
    salary_revisions: ['contracts'],
    bank: ['bank_accounts'],
    education: ['education_qualifications'],
    work_experience: ['work_experiences'],
    vaccination: ['vaccinations'],
    languages: ['languages'],
    training: ['trainings', 'courses'],
    sea_service: ['sea_services', 'vessel_types', 'vessels', 'clients'],
    documents: ['documents', 'document_types'],
};

export function employeeProfileTabPropsMissing(
    tab: EmployeeTab,
    props: Record<string, unknown>,
): string[] {
    return EMPLOYEE_PROFILE_TAB_PROPS[tab].filter(
        (key) => props[key] === undefined,
    );
}

export function isEmployeeProfileTabLoading(
    tab: EmployeeTab,
    props: Record<string, unknown>,
    isCreateMode: boolean,
): boolean {
    if (isCreateMode) {
        return false;
    }

    return employeeProfileTabPropsMissing(tab, props).length > 0;
}
