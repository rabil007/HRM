import type { EmployeeSummary } from '@/features/organization/documents/shared/types';

export type EmployeeFolderUploadConfig = {
    canShowAddDocument: boolean;
    dialogProps: {
        employeeId: number;
        employeeName: string;
        employeeNo: string | null;
        allowEmployeeSelection: false;
        partialReloadKeys: string[];
    };
};

export type LibraryUploadConfig = {
    canShowAddDocument: boolean;
    dialogProps: {
        employeeId: null;
        employeeName: string;
        allowEmployeeSelection: true;
        partialReloadKeys: string[];
    };
};

export type UploadDialogHeadingOptions = {
    allowEmployeeSelection: boolean;
    selectedEmployee?: { name: string; employee_no?: string | null } | null;
    employeeName?: string | null;
    employeeNo?: string | null;
};

export function resolveUploadDialogHeading({
    allowEmployeeSelection,
    selectedEmployee,
    employeeName,
    employeeNo,
}: UploadDialogHeadingOptions): { title: string; description: string } {
    if (allowEmployeeSelection) {
        return {
            title: 'Add Document to Library',
            description: selectedEmployee
                ? `Uploading documents for ${selectedEmployee.name}${selectedEmployee.employee_no ? ` (#${selectedEmployee.employee_no.replace(/^#/, '')})` : ''}. Select a file on the left, then enter its details on the right.`
                : 'Select an active employee, then add and configure files to upload to their document profile.',
        };
    }

    const cleanEmployeeNo = employeeNo ? employeeNo.replace(/^#/, '') : null;
    const employeeBadge = cleanEmployeeNo ? ` (#${cleanEmployeeNo})` : '';

    return {
        title: 'Add Document',
        description: `Adding documents for ${employeeName ?? ''}${employeeBadge}. Add files, then complete their details.`,
    };
}

export function resolveEmployeeFolderUploadConfig(
    employee: EmployeeSummary,
    canUpload: boolean,
    documentsTabVisible: boolean = true,
): EmployeeFolderUploadConfig {
    return {
        canShowAddDocument: Boolean(canUpload && documentsTabVisible),
        dialogProps: {
            employeeId: employee.id,
            employeeName: employee.name,
            employeeNo: employee.employee_no ?? null,
            allowEmployeeSelection: false,
            partialReloadKeys: ['documents', 'summary'],
        },
    };
}

export function resolveLibraryUploadConfig(
    canUpload: boolean,
): LibraryUploadConfig {
    return {
        canShowAddDocument: Boolean(canUpload),
        dialogProps: {
            employeeId: null,
            employeeName: '',
            allowEmployeeSelection: true,
            partialReloadKeys: [
                'employees',
                'searchDocuments',
                'complianceDocuments',
                'requirementDocuments',
                'summary',
                'requirement_summary',
            ],
        },
    };
}
