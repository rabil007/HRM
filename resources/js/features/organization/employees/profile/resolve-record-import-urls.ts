type RecordImportUrlConfig = {
    importUrl: (employeeId: number) => string;
    templateUrl: (employeeId: number) => string;
};

export function recordImportInputId(
    prefix: string,
    employeeId: number | null,
): string {
    return `${prefix}-${employeeId ?? 'draft'}`;
}

export function resolveRecordImportUrls(
    config: RecordImportUrlConfig,
    employeeId: number | null,
): { importUrl: string | null; templateUrl: string | null } {
    if (employeeId === null || employeeId <= 0) {
        return { importUrl: null, templateUrl: null };
    }

    return {
        importUrl: config.importUrl(employeeId),
        templateUrl: config.templateUrl(employeeId),
    };
}
