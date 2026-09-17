import type {
    ActiveOnVesselAssignment,
    BulkAddCrewRow,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
    EmployeeOperationalStatus,
} from '../types.ts';
import type { AssignmentReadinessGuidance } from './assignment-readiness-guidance.ts';
import { buildAssignmentReadinessGuidance } from './assignment-readiness-guidance.ts';
import type { BulkRowSummary } from './bulk-row-status.ts';
import { bulkRowIsBlocked, bulkRowIsIncomplete } from './bulk-row-status.ts';

export type BulkPreviewRowInput = BulkAddCrewRow & { key: string };

export type BulkSidebarMode = 'summary' | 'preview';
export type BulkPreviewFilter = 'all' | 'issues';

export type BulkRowState = 'ready' | 'blocked' | 'incomplete';

export type BulkPreviewRow = {
    rowKey: string;
    index: number;
    row: BulkPreviewRowInput;
    state: BulkRowState;
    status: EmployeeOperationalStatus | null;
    activeOnVessel: ActiveOnVesselAssignment | null;
};

export type BulkAttentionItem = {
    rowKey: string;
    index: number;
    employeeName: string;
    phaseLabel: string;
    summaryLine?: string;
    message: string;
    state: 'blocked' | 'incomplete';
};

export type BulkTargetSummary = {
    clientName?: string;
    vesselName?: string;
    plannedJoinLabel?: string;
};

function lookupStatus(
    formOptions: CrewAssignmentCreateFormOptions,
    employeeId: number | null,
): EmployeeOperationalStatus | null {
    if (employeeId == null) {
        return null;
    }

    return (
        formOptions.employee_status_by_employee?.[String(employeeId)] ?? null
    );
}

function lookupActiveOnVessel(
    formOptions: CrewAssignmentCreateFormOptions,
    employeeId: number | null,
): ActiveOnVesselAssignment | null {
    if (employeeId == null) {
        return null;
    }

    return (
        formOptions.active_on_vessel_by_employee?.[String(employeeId)] ?? null
    );
}

function resolveEmployeeName(
    formOptions: CrewAssignmentCreateFormOptions,
    employeeId: number | null,
): string {
    if (employeeId == null) {
        return 'Incomplete row';
    }

    return (
        formOptions.employees.find((employee) => employee.id === employeeId)
            ?.name ?? 'Selected employee'
    );
}

function phaseLabelFromStatus(
    status: EmployeeOperationalStatus | null,
): string {
    if (!status) {
        return 'Incomplete';
    }

    const code = status.current_phase?.toUpperCase() ?? '';
    const label = status.label ?? '';

    return code ? `${code} · ${label}` : label;
}

function summaryLineFromStatus(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
): string | undefined {
    const vesselName =
        status?.vessel_name ??
        status?.current_vessel ??
        activeOnVessel?.vessel_name ??
        null;

    if (vesselName) {
        return vesselName;
    }

    return undefined;
}

export function classifyBulkRow(
    employeeId: number | null,
    status: EmployeeOperationalStatus | null | undefined,
): BulkRowState {
    if (bulkRowIsIncomplete(employeeId)) {
        return 'incomplete';
    }

    if (bulkRowIsBlocked(status)) {
        return 'blocked';
    }

    return 'ready';
}

export function buildBulkPreviewRows(
    rows: BulkPreviewRowInput[],
    formOptions: CrewAssignmentCreateFormOptions,
    filter: BulkPreviewFilter,
): BulkPreviewRow[] {
    const previewRows = rows.map((row, index) => {
        const status = lookupStatus(formOptions, row.employee_id);
        const activeOnVessel = lookupActiveOnVessel(
            formOptions,
            row.employee_id,
        );
        const state = classifyBulkRow(row.employee_id, status);

        return {
            rowKey: row.key,
            index,
            row,
            state,
            status,
            activeOnVessel,
        };
    });

    if (filter === 'issues') {
        return previewRows.filter(
            (item) => item.state === 'blocked' || item.state === 'incomplete',
        );
    }

    return previewRows;
}

export function buildBulkAttentionList(
    rows: BulkPreviewRowInput[],
    formOptions: CrewAssignmentCreateFormOptions,
): BulkAttentionItem[] {
    const items: BulkAttentionItem[] = [];

    for (const [index, row] of rows.entries()) {
        const status = lookupStatus(formOptions, row.employee_id);
        const activeOnVessel = lookupActiveOnVessel(
            formOptions,
            row.employee_id,
        );
        const state = classifyBulkRow(row.employee_id, status);

        if (state === 'ready') {
            continue;
        }

        items.push({
            rowKey: row.key,
            index,
            employeeName: resolveEmployeeName(formOptions, row.employee_id),
            phaseLabel: phaseLabelFromStatus(status),
            summaryLine: summaryLineFromStatus(status, activeOnVessel),
            message: compactBulkAttentionMessage(state, status),
            state,
        });
    }

    items.sort((left, right) => {
        if (left.state !== right.state) {
            return left.state === 'blocked' ? -1 : 1;
        }

        return left.index - right.index;
    });

    return items;
}

export function compactBulkAttentionMessage(
    state: BulkRowState,
    status: EmployeeOperationalStatus | null,
): string {
    if (state === 'incomplete') {
        return 'Select an employee or remove this row.';
    }

    switch (status?.status) {
        case 'on_vessel':
            return 'Cannot start another assignment.';
        case 'join_standby':
            return 'Already in active mobilisation.';
        case 'training':
            return 'Training is part of the current mobilisation.';
        case 'ready_to_join':
            return 'Ready to join — update current mobilisation instead.';
        case 'demob_standby':
            return 'Awaiting next movement on current assignment.';
        case 'home_redeploy':
            return 'Current mobilisation is in its final stage.';
        default:
            return 'Cannot start another assignment.';
    }
}

export function buildBulkTargetSummary({
    clientId,
    vesselId,
    plannedJoinAt,
    formOptions,
}: {
    clientId: number | null;
    vesselId: number | null;
    plannedJoinAt: string | null;
    formOptions: CrewAssignmentCreateFormOptions;
}): BulkTargetSummary {
    const clientName =
        clientId != null
            ? formOptions.clients.find((client) => client.id === clientId)?.name
            : undefined;
    const vesselName =
        vesselId != null
            ? formOptions.vessels.find((vessel) => vessel.id === vesselId)?.name
            : undefined;
    const plannedJoinLabel =
        plannedJoinAt && plannedJoinAt.trim() !== ''
            ? plannedJoinAt.slice(0, 10)
            : undefined;

    return {
        clientName,
        vesselName,
        plannedJoinLabel,
    };
}

export function resolveNextPreviewRowKey(
    rows: BulkPreviewRowInput[],
    formOptions: CrewAssignmentCreateFormOptions,
    removedIndex: number,
    filter: BulkPreviewFilter,
): string | null {
    const previewRows = buildBulkPreviewRows(rows, formOptions, filter);

    if (previewRows.length === 0) {
        return null;
    }

    const nextRow =
        previewRows.find((item) => item.index >= removedIndex) ??
        previewRows[previewRows.length - 1];

    return nextRow?.rowKey ?? null;
}

export function resolvePreviewIndex(
    previewRows: BulkPreviewRow[],
    previewRowKey: string | null,
): number {
    if (previewRowKey === null || previewRows.length === 0) {
        return 0;
    }

    const index = previewRows.findIndex(
        (item) => item.rowKey === previewRowKey,
    );

    return index >= 0 ? index : 0;
}

export function buildBulkEmployeePreviewGuidance(
    previewRow: BulkPreviewRow,
    context: {
        formOptions: CrewAssignmentCreateFormOptions;
        permissions: Pick<
            CrewAssignmentPagePermissions,
            'view' | 'update' | 'perform_movement' | 'cancel' | 'view_planning'
        >;
        destinationVesselId: number | null;
        destinationVesselName: string | null;
        plannedJoinAt: string | null;
    },
): AssignmentReadinessGuidance | null {
    if (previewRow.state === 'incomplete') {
        return null;
    }

    const employeeName = resolveEmployeeName(
        context.formOptions,
        previewRow.row.employee_id,
    );

    return buildAssignmentReadinessGuidance({
        status: previewRow.status,
        activeOnVessel: previewRow.activeOnVessel,
        destinationVesselId: context.destinationVesselId,
        destinationVesselName: context.destinationVesselName,
        plannedJoinAt: context.plannedJoinAt,
        employeeName,
        permissions: context.permissions,
        vessels: context.formOptions.vessels,
        maxHomeDays: context.formOptions.max_home_days,
    });
}

export type BlockedBulkRowRemovalResult = {
    rows: BulkPreviewRowInput[];
    ensureMinimumOneRow: boolean;
};

export function removeBlockedBulkRows(
    rows: BulkPreviewRowInput[],
    formOptions: CrewAssignmentCreateFormOptions,
): BlockedBulkRowRemovalResult | null {
    const blockedIndices = indicesOfBlockedRows(rows, formOptions);

    if (blockedIndices.length === 0) {
        return null;
    }

    const blockedSet = new Set(blockedIndices);
    const nextRows = rows.filter((_, index) => !blockedSet.has(index));

    return {
        rows: nextRows,
        ensureMinimumOneRow: nextRows.length === 0,
    };
}

export function bulkPreviewBlockedWarning(state: BulkRowState): string | null {
    if (state === 'blocked') {
        return 'Active assignment — cannot be included in this Start batch.';
    }

    return null;
}

export function bulkReadyLabel(summary: BulkRowSummary): string | null {
    if (summary.readyCount === 0) {
        return null;
    }

    if (summary.blockedCount === 0 && summary.incompleteCount === 0) {
        return null;
    }

    if (summary.readyCount === 1) {
        return '1 other crew member is ready';
    }

    return `${summary.readyCount} other crew members are ready`;
}

export function bulkStartHelperText(summary: BulkRowSummary): string | null {
    if (summary.blockedCount > 0) {
        return summary.blockedCount === 1
            ? '1 crew member needs attention before this batch can start.'
            : `${summary.blockedCount} crew members need attention before this batch can start.`;
    }

    if (summary.incompleteCount > 0) {
        return summary.incompleteCount === 1
            ? '1 crew row is incomplete.'
            : `${summary.incompleteCount} crew rows are incomplete.`;
    }

    return null;
}

export function indicesOfBlockedRows(
    rows: BulkPreviewRowInput[],
    formOptions: CrewAssignmentCreateFormOptions,
): number[] {
    return rows.flatMap((row, index) => {
        const status = lookupStatus(formOptions, row.employee_id);

        return classifyBulkRow(row.employee_id, status) === 'blocked'
            ? [index]
            : [];
    });
}
