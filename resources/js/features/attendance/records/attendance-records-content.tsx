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
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { TableRowActions } from '@/components/table-row-actions';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
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
    const [filterDraft, setFilterDraft] = useState({
        date_from: initialFilters.date_from,
        date_to: initialFilters.date_to,
        employee_id: initialFilters.employee_id,
        status: initialFilters.status,
        source: initialFilters.source,
    });

    const form = useForm(defaultAttendanceRecordFormData());

    const activeFiltersCount = [
        initialFilters.employee_id,
        initialFilters.status,
        initialFilters.source,
    ].filter(Boolean).length;

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

    const applyFilterDraft = () => {
        list.applyFilters(filterDraft);
        setIsFiltersOpen(false);
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

            <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative min-w-0 flex-1">
                    <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search by employee..."
                        value={list.searchInput}
                        onChange={(e) => list.onSearchChange(e.target.value)}
                        className="h-12 w-full rounded-xl border-input bg-background/80 pl-10 text-sm dark:border-white/5 dark:bg-white/5"
                    />
                </div>
                <Button
                    variant="outline"
                    className="h-12 rounded-xl"
                    onClick={() => {
                        setFilterDraft({
                            date_from: initialFilters.date_from,
                            date_to: initialFilters.date_to,
                            employee_id: initialFilters.employee_id,
                            status: initialFilters.status,
                            source: initialFilters.source,
                        });
                        setIsFiltersOpen(true);
                    }}
                >
                    <Filter className="mr-2 h-4 w-4" />
                    Filters
                    {activeFiltersCount > 0 ? ` (${activeFiltersCount})` : ''}
                </Button>
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span>
                    {formatDisplayDate(initialFilters.date_from)} – {formatDisplayDate(initialFilters.date_to)}
                </span>
            </div>

            {records.length === 0 ? (
                <EmptyState
                    title="No attendance records"
                    description="Adjust filters or add a manual record."
                />
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

            <Sheet open={isFiltersOpen} onOpenChange={setIsFiltersOpen}>
                <SheetContent side="right" className="w-full sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>Filters</SheetTitle>
                    </SheetHeader>
                    <div className="mt-6 space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="filter_date_from">From</Label>
                                <Input
                                    id="filter_date_from"
                                    type="date"
                                    value={filterDraft.date_from}
                                    onChange={(e) =>
                                        setFilterDraft((prev) => ({ ...prev, date_from: e.target.value }))
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="filter_date_to">To</Label>
                                <Input
                                    id="filter_date_to"
                                    type="date"
                                    value={filterDraft.date_to}
                                    onChange={(e) =>
                                        setFilterDraft((prev) => ({ ...prev, date_to: e.target.value }))
                                    }
                                />
                            </div>
                        </div>

                        {can.manage ? (
                            <div className="space-y-2">
                                <Label>Employee</Label>
                                <AppSelect
                                    value={filterDraft.employee_id || 'all'}
                                    onValueChange={(value) =>
                                        setFilterDraft((prev) => ({
                                            ...prev,
                                            employee_id: value === 'all' ? '' : value,
                                        }))
                                    }
                                    variant="card"
                                >
                                    <AppSelectItem value="all">All employees</AppSelectItem>
                                    {employees.map((employee) => (
                                        <AppSelectItem key={employee.id} value={String(employee.id)}>
                                            {employee.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>
                        ) : null}

                        <div className="space-y-2">
                            <Label>Status</Label>
                            <AppSelect
                                value={filterDraft.status || 'all'}
                                onValueChange={(value) =>
                                    setFilterDraft((prev) => ({
                                        ...prev,
                                        status: value === 'all' ? '' : value,
                                    }))
                                }
                                variant="card"
                            >
                                <AppSelectItem value="all">All statuses</AppSelectItem>
                                {status_options.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <div className="space-y-2">
                            <Label>Source</Label>
                            <AppSelect
                                value={filterDraft.source || 'all'}
                                onValueChange={(value) =>
                                    setFilterDraft((prev) => ({
                                        ...prev,
                                        source: value === 'all' ? '' : value,
                                    }))
                                }
                                variant="card"
                            >
                                <AppSelectItem value="all">All sources</AppSelectItem>
                                {source_options.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <Button className="w-full" onClick={applyFilterDraft}>
                            Apply filters
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>
        </Main>
    );
}
