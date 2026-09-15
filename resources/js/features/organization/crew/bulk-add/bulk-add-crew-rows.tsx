import { Plus, Trash2 } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
} from '@/components/data-table';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { TableBody, TableHeader, TableRow } from '@/components/ui/table';
import {
    CrewEmployeeOperationalStatus,
    getEmployeeStatusContainerClass,
} from '@/features/organization/crew/components/crew-employee-operational-status';
import {
    bulkFieldError,
    bulkRowBlockReason,
    bulkRowIsBlocked,
} from '@/features/organization/crew/lib/bulk-row-status';
import type {
    ActiveOnVesselAssignment,
    BulkAddCrewRow,
    CrewAssignmentCreateFormOptions,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import { MOBILE_OPERATIONAL_LIST_CLASS } from '@/lib/mobile-operational-list';
import { cn } from '@/lib/utils';

export type BulkAddCrewRowState = BulkAddCrewRow & { key: string };

function lookupByEmployeeId<T>(
    map: Record<string, T> | undefined,
    employeeId: number | null,
): T | null {
    if (employeeId == null || map == null) {
        return null;
    }

    return map[String(employeeId)] ?? null;
}

export function BulkAddCrewRows({
    rows,
    formOptions,
    errors,
    onAddRow,
    onRemoveRow,
    onChangeRow,
}: {
    rows: BulkAddCrewRowState[];
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    onAddRow: () => void;
    onRemoveRow: (index: number) => void;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
}) {
    const selectedEmployeeIds = new Set(
        rows
            .map((row) => row.employee_id)
            .filter((id): id is number => id != null),
    );

    const crewError = bulkFieldError(errors, 'crew');

    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-sm font-semibold tracking-tight">
                    Crew members
                </h2>
                <p className="text-xs text-muted-foreground">
                    Rank defaults from the employee profile and can be changed
                    per row.
                </p>
            </div>

            {crewError ? <InputError message={crewError} /> : null}

            <div className="hidden md:block">
                <OrganizationDataTable minWidth="min-w-[720px]" compact>
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Employee</DataTableHead>
                            <DataTableHead>Rank</DataTableHead>
                            <DataTableHead>Operational Status</DataTableHead>
                            <DataTableHead className="w-16">
                                <span className="sr-only">Remove</span>
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row, index) => (
                            <BulkAddDesktopRow
                                key={row.key}
                                index={index}
                                row={row}
                                formOptions={formOptions}
                                errors={errors}
                                selectedEmployeeIds={selectedEmployeeIds}
                                onChangeRow={onChangeRow}
                                onRemoveRow={onRemoveRow}
                            />
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            </div>

            <div className={cn(MOBILE_OPERATIONAL_LIST_CLASS, 'space-y-3')}>
                {rows.map((row, index) => (
                    <BulkAddMobileCard
                        key={row.key}
                        index={index}
                        row={row}
                        formOptions={formOptions}
                        errors={errors}
                        selectedEmployeeIds={selectedEmployeeIds}
                        onChangeRow={onChangeRow}
                        onRemoveRow={onRemoveRow}
                    />
                ))}
            </div>

            <Button
                type="button"
                variant="outline"
                className="h-11 rounded-xl"
                onClick={onAddRow}
            >
                <Plus className="h-4 w-4" />
                Add Crew Member
            </Button>
        </section>
    );
}

function BulkAddDesktopRow({
    index,
    row,
    formOptions,
    errors,
    selectedEmployeeIds,
    onChangeRow,
    onRemoveRow,
}: {
    index: number;
    row: BulkAddCrewRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    selectedEmployeeIds: Set<number>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    onRemoveRow: (index: number) => void;
}) {
    const status = lookupByEmployeeId(
        formOptions.employee_status_by_employee,
        row.employee_id,
    );
    const activeOnVessel = lookupByEmployeeId(
        formOptions.active_on_vessel_by_employee,
        row.employee_id,
    );
    const blocked = bulkRowIsBlocked(status);
    const blockReason = bulkRowBlockReason(status, activeOnVessel);
    const employeeError = bulkFieldError(errors, `crew.${index}.employee_id`);
    const rankError = bulkFieldError(errors, `crew.${index}.rank_id`);

    return (
        <TableRow
            className={cn(
                dataTableBodyRowClass(false),
                blocked && 'bg-destructive/5 dark:bg-destructive/10',
            )}
        >
            <td className={dataTableCellClass()}>
                <EmployeeSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    selectedEmployeeIds={selectedEmployeeIds}
                    onChangeRow={onChangeRow}
                    error={employeeError}
                />
            </td>
            <td className={dataTableCellClass()}>
                <RankSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    onChangeRow={onChangeRow}
                    error={rankError}
                />
            </td>
            <td className={dataTableCellClass()}>
                <OperationalStatusCell
                    status={status}
                    activeOnVessel={activeOnVessel}
                    companyTimezone={formOptions.company_timezone}
                    blockReason={blockReason}
                    compact
                />
            </td>
            <td className={dataTableActionsCellClass()}>
                <RemoveRowButton index={index} onRemoveRow={onRemoveRow} />
            </td>
        </TableRow>
    );
}

function BulkAddMobileCard({
    index,
    row,
    formOptions,
    errors,
    selectedEmployeeIds,
    onChangeRow,
    onRemoveRow,
}: {
    index: number;
    row: BulkAddCrewRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    selectedEmployeeIds: Set<number>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    onRemoveRow: (index: number) => void;
}) {
    const status = lookupByEmployeeId(
        formOptions.employee_status_by_employee,
        row.employee_id,
    );
    const activeOnVessel = lookupByEmployeeId(
        formOptions.active_on_vessel_by_employee,
        row.employee_id,
    );
    const blocked = bulkRowIsBlocked(status);
    const blockReason = bulkRowBlockReason(status, activeOnVessel);
    const employeeError = bulkFieldError(errors, `crew.${index}.employee_id`);
    const rankError = bulkFieldError(errors, `crew.${index}.rank_id`);

    return (
        <div
            className={cn(
                'space-y-4 rounded-xl border p-4',
                blocked
                    ? 'border-destructive/40 bg-destructive/5'
                    : status
                      ? getEmployeeStatusContainerClass(status.status)
                      : 'border-border/60 bg-muted/10',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Crew member {index + 1}
                </p>
                <RemoveRowButton index={index} onRemoveRow={onRemoveRow} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={`bulk-employee-${index}`}>Employee</Label>
                <EmployeeSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    selectedEmployeeIds={selectedEmployeeIds}
                    onChangeRow={onChangeRow}
                    error={employeeError}
                />
            </div>

            <div className="space-y-2">
                <Label htmlFor={`bulk-rank-${index}`}>Rank</Label>
                <RankSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    onChangeRow={onChangeRow}
                    error={rankError}
                />
            </div>

            <OperationalStatusCell
                status={status}
                activeOnVessel={activeOnVessel}
                companyTimezone={formOptions.company_timezone}
                blockReason={blockReason}
            />
        </div>
    );
}

function EmployeeSelect({
    index,
    row,
    formOptions,
    selectedEmployeeIds,
    onChangeRow,
    error,
}: {
    index: number;
    row: BulkAddCrewRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    selectedEmployeeIds: Set<number>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    error?: string;
}) {
    return (
        <div className="space-y-1.5">
            <AppSelect
                value={row.employee_id?.toString() ?? ''}
                onValueChange={(value) => {
                    const employeeId = value ? Number(value) : null;
                    const employee = formOptions.employees.find(
                        (item) => item.id === employeeId,
                    );

                    onChangeRow(index, {
                        employee_id: employeeId,
                        rank_id: employee?.rank_id ?? null,
                    });
                }}
                variant="dark"
                placeholder="Select employee..."
                searchPlaceholder="Search employee..."
            >
                <AppSelectItem value="">Select employee...</AppSelectItem>
                {formOptions.employees
                    .filter(
                        (employee) =>
                            employee.id === row.employee_id ||
                            !selectedEmployeeIds.has(employee.id),
                    )
                    .map((employee) => (
                        <AppSelectItem
                            key={employee.id}
                            value={String(employee.id)}
                        >
                            {employee.name}
                            {employee.employee_no
                                ? ` · ${employee.employee_no}`
                                : ''}
                        </AppSelectItem>
                    ))}
            </AppSelect>
            <InputError message={error} id={`bulk-employee-${index}-error`} />
        </div>
    );
}

function RankSelect({
    index,
    row,
    formOptions,
    onChangeRow,
    error,
}: {
    index: number;
    row: BulkAddCrewRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    error?: string;
}) {
    return (
        <div className="space-y-1.5">
            <AppSelect
                value={row.rank_id?.toString() ?? ''}
                onValueChange={(value) =>
                    onChangeRow(index, {
                        ...row,
                        rank_id: value ? Number(value) : null,
                    })
                }
                variant="dark"
                placeholder="Select rank..."
                searchPlaceholder="Search rank..."
            >
                <AppSelectItem value="">Select rank...</AppSelectItem>
                {formOptions.ranks.map((rank) => (
                    <AppSelectItem key={rank.id} value={String(rank.id)}>
                        {rank.name}
                    </AppSelectItem>
                ))}
            </AppSelect>
            <InputError message={error} id={`bulk-rank-${index}-error`} />
        </div>
    );
}

function OperationalStatusCell({
    status,
    activeOnVessel,
    companyTimezone,
    blockReason,
    compact = false,
}: {
    status: EmployeeOperationalStatus | null;
    activeOnVessel: ActiveOnVesselAssignment | null;
    companyTimezone?: string;
    blockReason: string | null;
    compact?: boolean;
}) {
    if (!status) {
        return (
            <p className="text-xs text-muted-foreground">Select an employee</p>
        );
    }

    if (compact) {
        return (
            <div className="space-y-1">
                <p className="text-sm font-medium">{status.label}</p>
                {blockReason ? (
                    <p className="text-xs font-medium text-destructive">
                        {blockReason}
                    </p>
                ) : null}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {blockReason ? (
                <p className="text-xs font-medium text-destructive">
                    {blockReason}
                </p>
            ) : null}
            <CrewEmployeeOperationalStatus
                status={status}
                activeOnVessel={activeOnVessel}
                companyTimezone={companyTimezone}
            />
        </div>
    );
}

function RemoveRowButton({
    index,
    onRemoveRow,
}: {
    index: number;
    onRemoveRow: (index: number) => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-9 w-9 rounded-lg"
            onClick={() => onRemoveRow(index)}
            aria-label={`Remove crew member ${index + 1}`}
        >
            <Trash2 className="h-4 w-4" />
        </Button>
    );
}
