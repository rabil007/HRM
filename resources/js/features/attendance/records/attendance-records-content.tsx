import { useForm } from '@inertiajs/react';
import { Filter, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { TableRowActions } from '@/components/table-row-actions';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import type { PaginationMeta } from '@/types/pagination';
import { RecordDeleteDialog } from './components/record-delete-dialog';
import { RecordFiltersSheet } from './components/record-filters-sheet';
import { RecordFormSheet } from './components/record-form-sheet';
import { RecordStatusBadge } from './components/record-status-badge';
import {
    attendanceRecordToFormData,
    defaultAttendanceRecordFormData,
    EMPTY_ATTENDANCE_RECORD_FILTERS,
    type AttendanceRecord,
    type AttendanceRecordFilters,
    type AttendanceRecordPermissions,
} from './types';

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
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentRecord, setCurrentRecord] = useState<AttendanceRecord | null>(null);

    const form = useForm(defaultAttendanceRecordFormData());

    const filters: AttendanceRecordFilters = {
        date_from: initialFilters.date_from,
        date_to: initialFilters.date_to,
        employee_id: initialFilters.employee_id,
        status: initialFilters.status,
        source: initialFilters.source,
    };

    const activeFiltersCount = [initialFilters.employee_id, initialFilters.status, initialFilters.source].filter(
        Boolean,
    ).length;

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

    const handleFiltersChange = (next: AttendanceRecordFilters) => {
        list.applyFilters(next);
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

            <div className="mb-8 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search by employee..."
                        value={list.searchInput}
                        onChange={(e) => list.onSearchChange(e.target.value)}
                        className="h-12 w-full rounded-xl border-input bg-background/80 pl-10 text-sm dark:border-white/5 dark:bg-white/5"
                    />
                </div>

                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        className="glass-card h-12 rounded-xl px-5 hover:bg-accent"
                        onClick={() => setIsFiltersOpen(true)}
                    >
                        <Filter className="mr-2 h-4 w-4" />
                        Filters
                        {activeFiltersCount > 0 ? (
                            <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                {activeFiltersCount}
                            </span>
                        ) : null}
                    </Button>
                </div>
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span>
                    {formatDisplayDate(initialFilters.date_from)} – {formatDisplayDate(initialFilters.date_to)}
                </span>
            </div>

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
                                        {formatDisplayDateTime(record.clock_in)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {formatDisplayDateTime(record.clock_out)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {record.hours_worked ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <RecordStatusBadge status={record.status} />
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>{record.source}</TableCell>
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

            <RecordFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                employees={employees}
                statusOptions={status_options}
                sourceOptions={source_options}
                showEmployeeFilter={can.manage}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange(EMPTY_ATTENDANCE_RECORD_FILTERS)}
            />
        </Main>
    );
}
