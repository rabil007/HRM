import { Plus, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useRef } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TableBody, TableHeader, TableRow } from '@/components/ui/table';
import {
    CrewEmployeeOperationalStatus,
    getEmployeeStatusContainerClass,
} from '@/features/organization/crew/components/crew-employee-operational-status';
import { CrewMemberFields } from '@/features/organization/crew/components/crew-member-fields';
import {
    bulkFieldError,
    bulkRowBlockReason,
    bulkRowIsBlocked,
    bulkRowIsIncomplete,
} from '@/features/organization/crew/lib/bulk-row-status';
import type {
    ActiveOnVesselAssignment,
    BulkAddCrewRow,
    CrewAssignmentCreateFormOptions,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import { MOBILE_OPERATIONAL_LIST_CLASS } from '@/lib/mobile-operational-list';
import { cn } from '@/lib/utils';

export type CrewMemberRowState = BulkAddCrewRow & { key: string };

function lookupByEmployeeId<T>(
    map: Record<string, T> | undefined,
    employeeId: number | null,
): T | null {
    if (employeeId == null || map == null) {
        return null;
    }

    return map[String(employeeId)] ?? null;
}

export function CrewMembersSection({
    rows,
    formOptions,
    errors,
    compact,
    canAddRow,
    focusedRowKey = null,
    scrollFocusedRow = false,
    onAddRow,
    onRemoveRow,
    onChangeRow,
}: {
    rows: CrewMemberRowState[];
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    compact: boolean;
    canAddRow: boolean;
    focusedRowKey?: string | null;
    scrollFocusedRow?: boolean;
    onAddRow: () => void;
    onRemoveRow: (index: number) => void;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
}): ReactElement {
    const focusedRowRef = useRef<HTMLTableRowElement | null>(null);
    const focusedCardRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!scrollFocusedRow || focusedRowKey == null) {
            return;
        }

        focusedRowRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
        });
        focusedCardRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
        });
    }, [focusedRowKey, scrollFocusedRow]);
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
                    Crew Members
                </h2>
                <p className="text-xs text-muted-foreground">
                    {compact
                        ? 'Rank defaults from the employee profile and can be changed per row.'
                        : 'Assign who this mobilisation cycle belongs to.'}
                </p>
            </div>

            {crewError ? <InputError message={crewError} /> : null}

            {!compact && rows[0] ? (
                <SingleCrewMemberCard
                    row={rows[0]}
                    formOptions={formOptions}
                    errors={errors}
                    selectedEmployeeIds={selectedEmployeeIds}
                    onChangeRow={onChangeRow}
                />
            ) : (
                <>
                    <div className="hidden md:block">
                        <OrganizationDataTable minWidth="min-w-[720px]" compact>
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Employee</DataTableHead>
                                    <DataTableHead>Rank</DataTableHead>
                                    <DataTableHead>Arrival Date</DataTableHead>
                                    <DataTableHead>
                                        Operational Status
                                    </DataTableHead>
                                    <DataTableHead className="w-16">
                                        <span className="sr-only">Remove</span>
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, index) => (
                                    <BulkDesktopRow
                                        key={row.key}
                                        index={index}
                                        row={row}
                                        formOptions={formOptions}
                                        errors={errors}
                                        selectedEmployeeIds={
                                            selectedEmployeeIds
                                        }
                                        canRemove={rows.length > 1}
                                        isFocused={focusedRowKey === row.key}
                                        rowRef={
                                            focusedRowKey === row.key
                                                ? focusedRowRef
                                                : undefined
                                        }
                                        onChangeRow={onChangeRow}
                                        onRemoveRow={onRemoveRow}
                                    />
                                ))}
                            </TableBody>
                        </OrganizationDataTable>
                    </div>

                    <div
                        className={cn(
                            MOBILE_OPERATIONAL_LIST_CLASS,
                            'space-y-3',
                        )}
                    >
                        {rows.map((row, index) => (
                            <BulkMobileCard
                                key={row.key}
                                index={index}
                                row={row}
                                formOptions={formOptions}
                                errors={errors}
                                selectedEmployeeIds={selectedEmployeeIds}
                                canRemove={rows.length > 1}
                                isFocused={focusedRowKey === row.key}
                                cardRef={
                                    focusedRowKey === row.key
                                        ? focusedCardRef
                                        : undefined
                                }
                                onChangeRow={onChangeRow}
                                onRemoveRow={onRemoveRow}
                            />
                        ))}
                    </div>
                </>
            )}

            {canAddRow ? (
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 rounded-xl"
                    onClick={onAddRow}
                >
                    <Plus className="h-4 w-4" />
                    Add Another Crew Member
                </Button>
            ) : null}
        </section>
    );
}

function SingleCrewMemberCard({
    row,
    formOptions,
    errors,
    selectedEmployeeIds,
    onChangeRow,
}: {
    row: CrewMemberRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    selectedEmployeeIds: Set<number>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
}): ReactElement {
    const status = lookupByEmployeeId(
        formOptions.employee_status_by_employee,
        row.employee_id,
    );
    const employeeContainerClass = status
        ? getEmployeeStatusContainerClass(status.status)
        : 'border-border/60 bg-muted/10';

    return (
        <div
            className={cn(
                'rounded-xl border p-4 transition-colors',
                employeeContainerClass,
            )}
        >
            <CrewMemberFields
                data={row}
                onChange={(nextData) =>
                    onChangeRow(0, {
                        ...row,
                        ...nextData,
                    })
                }
                formOptions={formOptions}
                errors={errors}
                showOperationalStatus
                selectedEmployeeIds={selectedEmployeeIds}
                employeeErrorKey="employee_id"
                rankErrorKey="rank_id"
                arrivalErrorKey="planned_arrival_at"
            />
        </div>
    );
}

function BulkDesktopRow({
    index,
    row,
    formOptions,
    errors,
    selectedEmployeeIds,
    canRemove,
    isFocused = false,
    rowRef,
    onChangeRow,
    onRemoveRow,
}: {
    index: number;
    row: CrewMemberRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    selectedEmployeeIds: Set<number>;
    canRemove: boolean;
    isFocused?: boolean;
    rowRef?: React.RefObject<HTMLTableRowElement | null>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    onRemoveRow: (index: number) => void;
}): ReactElement {
    const status = lookupByEmployeeId(
        formOptions.employee_status_by_employee,
        row.employee_id,
    );
    const activeOnVessel = lookupByEmployeeId(
        formOptions.active_on_vessel_by_employee,
        row.employee_id,
    );
    const incomplete = bulkRowIsIncomplete(row.employee_id);
    const blocked = bulkRowIsBlocked(status);
    const blockReason = bulkRowBlockReason(status, activeOnVessel);

    return (
        <TableRow
            ref={rowRef}
            data-bulk-row-key={row.key}
            className={cn(
                dataTableBodyRowClass(false),
                (blocked || incomplete) &&
                    'bg-destructive/5 dark:bg-destructive/10',
                isFocused && 'bg-primary/5 ring-2 ring-primary/40 ring-inset',
            )}
        >
            <td className={dataTableCellClass()}>
                <EmployeeSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    selectedEmployeeIds={selectedEmployeeIds}
                    onChangeRow={onChangeRow}
                    error={bulkFieldError(errors, `crew.${index}.employee_id`)}
                />
            </td>
            <td className={dataTableCellClass()}>
                <RankSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    onChangeRow={onChangeRow}
                    error={bulkFieldError(errors, `crew.${index}.rank_id`)}
                />
            </td>
            <td className={dataTableCellClass()}>
                <Input
                    type="date"
                    className="h-10 text-xs"
                    value={row.planned_arrival_at ?? ''}
                    onChange={(event) =>
                        onChangeRow(index, {
                            ...row,
                            planned_arrival_at: event.target.value || null,
                        })
                    }
                />
                <InputError
                    message={bulkFieldError(
                        errors,
                        `crew.${index}.planned_arrival_at`,
                    )}
                />
            </td>
            <td className={dataTableCellClass()}>
                <OperationalStatusCell
                    status={status}
                    activeOnVessel={activeOnVessel}
                    companyTimezone={formOptions.company_timezone}
                    blockReason={blockReason}
                    incomplete={incomplete}
                    compact
                />
            </td>
            <td className={dataTableActionsCellClass()}>
                {canRemove ? (
                    <RemoveRowButton index={index} onRemoveRow={onRemoveRow} />
                ) : null}
            </td>
        </TableRow>
    );
}

function BulkMobileCard({
    index,
    row,
    formOptions,
    errors,
    selectedEmployeeIds,
    canRemove,
    isFocused = false,
    cardRef,
    onChangeRow,
    onRemoveRow,
}: {
    index: number;
    row: CrewMemberRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    selectedEmployeeIds: Set<number>;
    canRemove: boolean;
    isFocused?: boolean;
    cardRef?: React.RefObject<HTMLDivElement | null>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    onRemoveRow: (index: number) => void;
}): ReactElement {
    const status = lookupByEmployeeId(
        formOptions.employee_status_by_employee,
        row.employee_id,
    );
    const activeOnVessel = lookupByEmployeeId(
        formOptions.active_on_vessel_by_employee,
        row.employee_id,
    );
    const incomplete = bulkRowIsIncomplete(row.employee_id);
    const blocked = bulkRowIsBlocked(status);
    const blockReason = bulkRowBlockReason(status, activeOnVessel);

    return (
        <div
            ref={cardRef}
            data-bulk-row-key={row.key}
            className={cn(
                'space-y-4 rounded-xl border p-4',
                blocked || incomplete
                    ? 'border-destructive/40 bg-destructive/5'
                    : status
                      ? getEmployeeStatusContainerClass(status.status)
                      : 'border-border/60 bg-muted/10',
                isFocused && 'ring-2 ring-primary/40',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Crew member {index + 1}
                </p>
                {canRemove ? (
                    <RemoveRowButton index={index} onRemoveRow={onRemoveRow} />
                ) : null}
            </div>

            <div className="space-y-2">
                <Label htmlFor={`bulk-employee-${index}`}>Employee</Label>
                <EmployeeSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    selectedEmployeeIds={selectedEmployeeIds}
                    onChangeRow={onChangeRow}
                    error={bulkFieldError(errors, `crew.${index}.employee_id`)}
                />
            </div>

            <div className="space-y-2">
                <Label htmlFor={`bulk-rank-${index}`}>Rank</Label>
                <RankSelect
                    index={index}
                    row={row}
                    formOptions={formOptions}
                    onChangeRow={onChangeRow}
                    error={bulkFieldError(errors, `crew.${index}.rank_id`)}
                />
            </div>

            <div className="space-y-2">
                <Label htmlFor={`bulk-arrival-${index}`}>
                    Arrival Date (optional)
                </Label>
                <Input
                    id={`bulk-arrival-${index}`}
                    type="date"
                    className="h-11"
                    value={row.planned_arrival_at ?? ''}
                    onChange={(event) =>
                        onChangeRow(index, {
                            ...row,
                            planned_arrival_at: event.target.value || null,
                        })
                    }
                />
                <InputError
                    message={bulkFieldError(
                        errors,
                        `crew.${index}.planned_arrival_at`,
                    )}
                />
            </div>

            <OperationalStatusCell
                status={status}
                activeOnVessel={activeOnVessel}
                companyTimezone={formOptions.company_timezone}
                blockReason={blockReason}
                incomplete={incomplete}
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
    row: CrewMemberRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    selectedEmployeeIds: Set<number>;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    error?: string;
}): ReactElement {
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
                        ...row,
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
    row: CrewMemberRowState;
    formOptions: CrewAssignmentCreateFormOptions;
    onChangeRow: (index: number, row: BulkAddCrewRow) => void;
    error?: string;
}): ReactElement {
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
    incomplete = false,
    compact = false,
}: {
    status: EmployeeOperationalStatus | null;
    activeOnVessel: ActiveOnVesselAssignment | null;
    companyTimezone?: string;
    blockReason: string | null;
    incomplete?: boolean;
    compact?: boolean;
}): ReactElement {
    if (incomplete) {
        return (
            <div className="space-y-1">
                <p className="text-sm font-medium">Incomplete</p>
                <p className="text-xs font-medium text-destructive">
                    Select an employee or remove this row.
                </p>
            </div>
        );
    }

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
}): ReactElement {
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
