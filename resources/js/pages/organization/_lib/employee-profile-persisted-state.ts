import type { EmployeeDetails } from '../employee-page.types.ts';
import {
    isDraftEmployeeNumber,
    isOfficialEmployeeNumberMissing,
} from './draft-employee-number.ts';

export type EnsuredEmployeeSnapshot = {
    id: number;
    name: string;
    employee_no: string;
};

/**
 * Apply ensureEmployee response to persisted employee state only.
 * Never copy unsaved form values into persisted employee_no.
 */
export function mergePersistedEmployeeAfterEnsure(
    current: EmployeeDetails,
    ensured: EnsuredEmployeeSnapshot,
): EmployeeDetails {
    return {
        ...current,
        id: ensured.id,
        name: ensured.name,
        employee_no: ensured.employee_no,
    };
}

export type EmployeeNumberHeaderState = {
    displayValue: string;
    isProvisional: boolean;
    hasUnsavedOfficialNumber: boolean;
    helperMessage: string | null;
};

export function resolveEmployeeNumberHeaderState(
    persistedEmployeeNo: unknown,
    formEmployeeNo: unknown,
): EmployeeNumberHeaderState {
    const persisted = String(persistedEmployeeNo ?? '').trim();
    const form = String(formEmployeeNo ?? '').trim();
    const persistedIsDraft = isDraftEmployeeNumber(persisted);
    const formIsOfficial = !isOfficialEmployeeNumberMissing(form);

    const hasUnsavedOfficialNumber =
        formIsOfficial && (persistedIsDraft || form !== persisted);

    const isProvisional = persistedIsDraft && !formIsOfficial;

    let displayValue = '';

    if (formIsOfficial) {
        displayValue = form;
    } else if (!persistedIsDraft && persisted !== '') {
        displayValue = persisted;
    }

    let helperMessage: string | null = null;

    if (hasUnsavedOfficialNumber) {
        helperMessage = 'Official employee number not saved yet.';
    } else if (isProvisional) {
        helperMessage =
            'Temporary employee ID — enter the official employee number';
    }

    return {
        displayValue,
        isProvisional,
        hasUnsavedOfficialNumber,
        helperMessage,
    };
}

export function canEditEmployeeProfile(
    permissions: string[],
    options: {
        isCreateMode: boolean;
        persistedEmployeeNo: unknown;
    },
): boolean {
    if (permissions.includes('employees.update')) {
        return true;
    }

    if (!permissions.includes('employees.create')) {
        return false;
    }

    return (
        options.isCreateMode ||
        isDraftEmployeeNumber(options.persistedEmployeeNo)
    );
}

/**
 * Inertia preserveState for employee profile saves.
 *
 * Create-mode successes often redirect to the same React component
 * (`organization/employee` in create mode). Preserving state would keep the
 * previous provisional employee id/form values on a blank create screen.
 * Validation 422 responses do not remount the page, so entered values remain.
 *
 * Edit-mode keeps preserveState so successful saves sync without blanking.
 */
export function resolveEmployeeProfilePreserveState(options: {
    isCreateMode: boolean;
}): boolean {
    return !options.isCreateMode;
}

/**
 * Detect a server-delivered fresh create page after a successful finalize
 * redirect (placeholder employee, no resume id).
 */
export function isFreshEmployeeCreatePage(options: {
    isCreateMode: boolean;
    employeeId: number | null | undefined;
}): boolean {
    if (!options.isCreateMode) {
        return false;
    }

    return options.employeeId === null || options.employeeId === undefined;
}
