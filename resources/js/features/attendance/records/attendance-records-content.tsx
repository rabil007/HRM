import { useForm } from '@inertiajs/react';
import { Filter, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { TableRowActions } from '@/components/table-row-actions';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate, formatDisplayDateTime12h } from '@/lib/format-date';
import type { PaginationMeta } from '@/types/pagination';
import { RecordDeleteDialog } from './components/record-delete-dialog';
import { RecordFormSheet } from './components/record-form-sheet';
import { RecordStatusBadge } from './components/record-status-badge';
import {
    attendanceRecordToFormData,
    defaultAttendanceRecordFormData,
    type AttendanceRecord,
    type AttendanceRecordFilters,
    type AttendanceRecordPermissions,
} from './types';

const filterInputClass =
    'h-10 rounded-xl border-input bg-background/50 dark:border-white/10 dark:bg-white/5 focus-visible:ring-primary/40';

export function AttendanceRecordsContent({
    records,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    employees,
    status_options,
    source_options,
    linkedEmployeeId,
    can,
}: {
    records: AttendanceRecord[];
    pagination: PaginationMeta;
    search: string;
    filters: AttendanceRecordFilters & { search?: string };
    employees: Array<{ id: number; employee_no: string | null; name: string }>;
    status_options: Array<{ value: string; label: string }>;
    source_options: Array<{ value: string; label: string }>;
    linkedEmployeeId: number | null;
    can: AttendanceRecordPermissions;
}) {
    const list = useServerPaginationFilters({
        url: '/attendance/records',
        search: initialSearch,
        filters: {
            date_from: initialFilters.date_from,
            date_to: initialFilters.date_to,
            employee_id: initialFilters.employee_id,
            status: initialFilters.status,
            source: initialFilters.source,
        },
        pagination,
    });

    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentRecord, setCurrentRecord] = useState<AttendanceRecord | null>(null);

    const form = useForm(defaultAttendanceRecordFormData());

    const filters: AttendanceRecordFilters = {
        date_from: initialFilters.date_from,
        date_to: initialFilters.date_to,
        employee_id: initialFilters.employee_id,
        status: initialFilters.status,
        source: initialFilters.source,
    };

    const activeFilterCount = useMemo(
        () =>
            [
                initialSearch,
                filters.date_from,
                filters.date_to,
                filters.employee_id,
                filters.status,
                filters.source,
            ].filter(Boolean).length,
        [filters, initialSearch],
    );

    const applyFilters = (next: Partial<AttendanceRecordFilters>) => {
        list.applyFilters({
            date_from: next.date_from ?? filters.date_from,
            date_to: next.date_to ?? filters.date_to,
            employee_id: next.employee_id ?? filters.employee_id,
            status: next.status ?? filters.status,
            source: next.source ?? filters.source,
        });
    };

    const clearFilters = () => {
        list.visit({
            search: null,
            date_from: null,
            date_to: null,
            employee_id: null,
            status: null,
            source: null,
            page: null,
        });
    };

    const handleAdd = () => {
        setCurrentRecord(null);
        form.reset();
        form.clearErrors();
        form.setData(defaultAttendanceRecordFormData(linkedEmployeeId));
        setIsSheetOpen(true);
    };

    const handleEdit = (record: AttendanceRecord) => {
        setCurrentRecord(record);
        form.reset();
        form.clearErrors();
        form.setData(attendanceRecordToFormData(record));
        setIsSheetOpen(true);
    };

    const handleDelete = (record: AttendanceRecord) => {
        setCurrentRecord(record);
        setIsDeleteOpen(true);
    };

    const submit = () => {
        if (!form.data.employee_id) {
            form.setError('employee_id', 'Employee is required.');

            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        };

        if (currentRecord) {
            form.put(`/attendance/records/${currentRecord.id}`, options);

            return;
        }

        form.post('/attendance/records', options);
    };

    return (
        <Main>
            <PageHeader
                title="Attendance records"
                description="View and manage daily attendance entries."
                right={
                    <div className="flex flex-wrap items-center gap-2">
                        {can.create ? (
                            <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                                <Plus className="mr-2 h-4 w-4" />
                                Add record
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <Card className="mb-6 border-border/80 bg-muted/20 dark:border-white/5 dark:bg-white/3">
                <CardContent className="p-5">
                    <div className="mb-4 flex items-center gap-3">
                        <Filter className="h-4 w-4 text-muted-foreground/50" />
                        <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground/50">
                            Filters
                        </span>
                        {activeFilterCount > 0 ? (
                            <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                {activeFilterCount} active
                            </Badge>
                        ) : null}
                        {activeFilterCount > 0 ? (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="ml-auto flex items-center gap-1 text-[11px] text-muted-foreground/50 transition-colors hover:text-foreground"
                            >
                                <X className="h-3 w-3" />
                                Clear all
                            </button>
                        ) : null}
                    </div>

                    <div
                        className={
                            can.manage
                                ? 'grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_9.5rem_9.5rem_11rem_10rem_10rem]'
                                : 'grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_9.5rem_9.5rem_10rem_10rem]'
                        }
                    >
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="attendance-records-search"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                Search
                            </label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground/40" />
                                <Input
                                    id="attendance-records-search"
                                    value={list.searchInput}
                                    onChange={(e) => list.onSearchChange(e.target.value)}
                                    placeholder="Search by employee…"
                                    className={`${filterInputClass} pl-10`}
                                />
                            </div>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="attendance-records-date-from"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                From
                            </label>
                            <Input
                                id="attendance-records-date-from"
                                type="date"
                                value={filters.date_from}
                                onChange={(e) => applyFilters({ date_from: e.target.value })}
                                className={`${filterInputClass} px-3 text-sm`}
                            />
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="attendance-records-date-to"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                To
                            </label>
                            <Input
                                id="attendance-records-date-to"
                                type="date"
                                value={filters.date_to}
                                onChange={(e) => applyFilters({ date_to: e.target.value })}
                                className={`${filterInputClass} px-3 text-sm`}
                            />
                        </div>

                        {can.manage ? (
                            <div className="flex min-w-0 flex-col gap-1.5">
                                <span className="text-[11px] font-medium text-muted-foreground/60">Employee</span>
                                <AppSelect
                                    value={filters.employee_id || ''}
                                    onValueChange={(value) => applyFilters({ employee_id: value })}
                                    variant="dark"
                                    placeholder="All employees"
                                    className="h-10"
                                >
                                    <AppSelectItem value="">All employees</AppSelectItem>
                                    {employees.map((employee) => (
                                        <AppSelectItem key={employee.id} value={String(employee.id)}>
                                            {employee.employee_no
                                                ? `${employee.employee_no} — ${employee.name}`
                                                : employee.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>
                        ) : null}

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="text-[11px] font-medium text-muted-foreground/60">Status</span>
                            <AppSelect
                                value={filters.status || ''}
                                onValueChange={(value) => applyFilters({ status: value })}
                                variant="dark"
                                placeholder="All statuses"
                                className="h-10"
                            >
                                <AppSelectItem value="">All statuses</AppSelectItem>
                                {status_options.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="text-[11px] font-medium text-muted-foreground/60">Source</span>
                            <AppSelect
                                value={filters.source || ''}
                                onValueChange={(value) => applyFilters({ source: value })}
                                variant="dark"
                                placeholder="All sources"
                                className="h-10"
                            >
                                <AppSelectItem value="">All sources</AppSelectItem>
                                {source_options.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {records.length === 0 ? (
                <EmptyState title="No attendance records" description="Adjust filters or add a manual record." />
            ) : (
                <>
                    <OrganizationDataTable>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Date</DataTableHead>
                                <DataTableHead>Clock in</DataTableHead>
                                <DataTableHead>Clock out</DataTableHead>
                                <DataTableHead>Hours</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead>Source</DataTableHead>
                                <DataTableHead className="text-right">Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {records.map((record) => (
                                <TableRow key={record.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>
                                        {record.employee?.name ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {formatDisplayDate(record.date)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {formatDisplayDateTime12h(record.clock_in)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {formatDisplayDateTime12h(record.clock_out)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {record.hours_worked ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <RecordStatusBadge status={record.status} />
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {record.clock_in || record.clock_out ? (record.source ?? '—') : '—'}
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass}>
                                        <TableRowActions
                                            actions={[
                                                {
                                                    label: 'Edit',
                                                    icon: Pencil,
                                                    onClick: () => handleEdit(record),
                                                    hidden: !can.update,
                                                },
                                                {
                                                    label: 'Delete',
                                                    icon: Trash2,
                                                    variant: 'danger',
                                                    onClick: () => handleDelete(record),
                                                    hidden: !can.delete,
                                                },
                                            ]}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination {...list.paginationProps} className="mt-6" />
                </>
            )}

            <RecordFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                record={currentRecord}
                form={form}
                employees={employees}
                statusOptions={status_options}
                linkedEmployeeId={linkedEmployeeId}
                canManage={can.manage}
                onSubmit={submit}
            />

            <RecordDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} record={currentRecord} />
        </Main>
    );
}
