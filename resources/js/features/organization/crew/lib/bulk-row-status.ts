import type {
    ActiveOnVesselAssignment,
    EmployeeOperationalStatus,
} from '../types';

export function bulkRowIsBlocked(
    status: EmployeeOperationalStatus | null | undefined,
): boolean {
    return status?.has_active_assignment === true;
}

export function bulkRowIsIncomplete(employeeId: number | null): boolean {
    return employeeId == null;
}

export type BulkRowSummary = {
    readyCount: number;
    blockedCount: number;
    incompleteCount: number;
};

export function summarizeBulkRows(
    rows: Array<{ employee_id: number | null }>,
    lookupStatus: (
        employeeId: number | null,
    ) => EmployeeOperationalStatus | null | undefined,
): BulkRowSummary {
    let readyCount = 0;
    let blockedCount = 0;
    let incompleteCount = 0;

    for (const row of rows) {
        if (bulkRowIsIncomplete(row.employee_id)) {
            incompleteCount += 1;
            continue;
        }

        if (bulkRowIsBlocked(lookupStatus(row.employee_id))) {
            blockedCount += 1;
        } else {
            readyCount += 1;
        }
    }

    return { readyCount, blockedCount, incompleteCount };
}

export function canSubmitBulkBatch(summary: BulkRowSummary): boolean {
    return (
        summary.readyCount >= 1 &&
        summary.blockedCount === 0 &&
        summary.incompleteCount === 0
    );
}

export function bulkRowBlockReason(
    status: EmployeeOperationalStatus | null | undefined,
    activeOnVessel: ActiveOnVesselAssignment | null | undefined,
): string | null {
    if (!bulkRowIsBlocked(status)) {
        return null;
    }

    if (status?.status === 'on_vessel') {
        const vesselName =
            status.vessel_name ??
            status.current_vessel ??
            activeOnVessel?.vessel_name ??
            null;

        if (vesselName) {
            return `On Vessel — already assigned to ${vesselName}. Use Transfer Vessel instead.`;
        }

        return 'On Vessel — already assigned. Use Transfer Vessel instead.';
    }

    const label = status?.label?.trim() || 'Active assignment';

    return `${label} — this employee already has an active Crew Assignment.`;
}

export function bulkFieldError(
    errors: Record<string, string | string[] | undefined>,
    key: string,
): string | undefined {
    const value = errors[key];

    if (typeof value === 'string' && value.trim() !== '') {
        return value;
    }

    if (
        Array.isArray(value) &&
        typeof value[0] === 'string' &&
        value[0] !== ''
    ) {
        return value[0];
    }

    return undefined;
}
