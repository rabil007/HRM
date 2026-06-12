import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { ListTableCrudActions } from '@/components/list-table-actions';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { LeaveTypeCard } from './components/leave-type-card';
import { LeaveTypeDeleteDialog } from './components/leave-type-delete-dialog';
import { LeaveTypeFormSheet } from './components/leave-type-form-sheet';
import { defaultLeaveTypeFormData, leaveTypeToFormData, type LeaveType } from './types';

export function AttendanceTypesContent({
    leave_types,
    pagination,
    search: initialSearch,
}: {
    leave_types: LeaveType[];
    pagination: PaginationMeta;
    search: string;
}) {
    const list = useServerPaginationFilters({
        url: '/attendance/types',
        search: initialSearch,
        filters: {},
        pagination,
    });
    const [view, setView] = useViewPreference('attendance-types:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentLeaveType, setCurrentLeaveType] = useState<LeaveType | null>(null);

    const form = useForm(defaultLeaveTypeFormData());

    const handleAdd = () => {
        setCurrentLeaveType(null);
        form.reset();
        form.clearErrors();
        form.setData(defaultLeaveTypeFormData());
        setIsSheetOpen(true);
    };

    const handleEdit = (leaveType: LeaveType) => {
        setCurrentLeaveType(leaveType);
        form.reset();
        form.clearErrors();
        form.setData(leaveTypeToFormData(leaveType));
        setIsSheetOpen(true);
    };

    const handleDelete = (leaveType: LeaveType) => {
        setCurrentLeaveType(leaveType);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentLeaveType) {
            return;
        }

        router.delete(`/attendance/types/${currentLeaveType.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentLeaveType(null);
            },
        });
    };

    const toggleStatus = (leaveType: LeaveType, enabled: boolean) => {
        router.put(
            `/attendance/types/${leaveType.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const submit = () => {
        if (currentLeaveType) {
            form.put(`/attendance/types/${currentLeaveType.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/attendance/types', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Attendance types"
                description="Manage leave categories such as annual leave and sick leave."
                right={
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Attendance Type
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search attendance types by name or code..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={<ViewToggle value={view} onChange={setView} />}
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {leave_types.map((leaveType) => (
                        <LeaveTypeCard
                            key={leaveType.id}
                            leaveType={leaveType}
                            onEdit={handleEdit}
                            onDelete={handleDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Name</DataTableHead>
                            <DataTableHead>Code</DataTableHead>
                            <DataTableHead>Days / year</DataTableHead>
                            <DataTableHead>Max carry</DataTableHead>
                            <DataTableHead>Color</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {leave_types.map((leaveType) => (
                            <TableRow key={leaveType.id} className={dataTableBodyRowClass()}>
                                <TableCell className={dataTableCellPrimaryClass()}>{leaveType.name}</TableCell>
                                <TableCell className={dataTableCellClass()}>{leaveType.code}</TableCell>
                                <TableCell className={dataTableCellClass()}>{leaveType.days_per_year}</TableCell>
                                <TableCell className={dataTableCellClass()}>{leaveType.max_carry_days}</TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <span
                                        className="inline-block h-5 w-5 rounded-full border border-border/60"
                                        style={{ backgroundColor: leaveType.color ?? '#94a3b8' }}
                                    />
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="flex items-center gap-3">
                                        <Switch
                                            checked={leaveType.status === 'active'}
                                            onCheckedChange={(checked) => toggleStatus(leaveType, checked)}
                                        />
                                        <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                            {leaveType.status}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableActionsCellClass()}>
                                    <ListTableCrudActions
                                        showView={false}
                                        onEdit={() => handleEdit(leaveType)}
                                        onDelete={() => handleDelete(leaveType)}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {leave_types.length === 0 ? <EmptyState title="No attendance types found." /> : null}

            <Pagination {...list.paginationProps} label="attendance types" />

            <LeaveTypeFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                leaveType={currentLeaveType}
                form={form}
                onSubmit={submit}
            />

            <LeaveTypeDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                leaveType={currentLeaveType}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
