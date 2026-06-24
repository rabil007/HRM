import { router, useForm } from '@inertiajs/react';
import { Filter, Plus, Search } from 'lucide-react';
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
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { LeaveRequestCancelDialog } from './components/leave-request-cancel-dialog';
import { LeaveRequestCard } from './components/leave-request-card';
import { LeaveRequestDeleteDialog } from './components/leave-request-delete-dialog';
import { LeaveRequestFiltersSheet } from './components/leave-request-filters-sheet';
import { LeaveRequestFormSheet } from './components/leave-request-form-sheet';
import { LeaveRequestRejectDialog } from './components/leave-request-reject-dialog';
import { LeaveRequestRowActions } from './components/leave-request-row-actions';
import { LeaveRequestStatusBadge } from './components/leave-request-status-badge';
import {
    defaultLeaveRequestFormData,
    leaveRequestToFormData
    
    
    
    
    
} from './types';
import type {LeaveRequest, LeaveRequestEmployeeOption, LeaveRequestFilters, LeaveRequestPermissions, LeaveRequestTypeOption} from './types';

export function LeaveRequestsContent({
    leave_requests,
    pagination,
    status_counts,
    search: initialSearch,
    filters: initialFilters,
    employees,
    leave_types,
    linkedEmployeeId,
    can,
}: {
    leave_requests: LeaveRequest[];
    pagination: PaginationMeta;
    status_counts: {
        all: number;
        pending: number;
        approved: number;
        rejected: number;
        cancelled: number;
    };
    search: string;
    filters: LeaveRequestFilters;
    employees: LeaveRequestEmployeeOption[];
    leave_types: LeaveRequestTypeOption[];
    linkedEmployeeId: number | null;
    can: LeaveRequestPermissions;
}) {
    const list = useServerPaginationFilters({
        url: '/attendance/leave-requests',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference('attendance-leave-requests:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isRejectOpen, setIsRejectOpen] = useState(false);
    const [isCancelOpen, setIsCancelOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentLeaveRequest, setCurrentLeaveRequest] = useState<LeaveRequest | null>(null);

    const filters: LeaveRequestFilters = {
        status: initialFilters.status,
        employee_id: initialFilters.employee_id,
        leave_type_id: initialFilters.leave_type_id,
    };

    const activeFiltersCount = [initialFilters.employee_id, initialFilters.leave_type_id].filter(Boolean).length;

    const form = useForm(defaultLeaveRequestFormData());

    const handleAdd = () => {
        setCurrentLeaveRequest(null);
        form.reset();
        form.clearErrors();
        form.setData({
            ...defaultLeaveRequestFormData(),
            employee_id: linkedEmployeeId ?? '',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (leaveRequest: LeaveRequest) => {
        setCurrentLeaveRequest(leaveRequest);
        form.reset();
        form.clearErrors();
        form.setData(leaveRequestToFormData(leaveRequest));
        setIsSheetOpen(true);
    };

    const handleDelete = (leaveRequest: LeaveRequest) => {
        setCurrentLeaveRequest(leaveRequest);
        setIsDeleteOpen(true);
    };

    const handleReject = (leaveRequest: LeaveRequest) => {
        setCurrentLeaveRequest(leaveRequest);
        setIsRejectOpen(true);
    };

    const handleCancel = (leaveRequest: LeaveRequest) => {
        setCurrentLeaveRequest(leaveRequest);
        setIsCancelOpen(true);
    };

    const confirmDelete = () => {
        if (!currentLeaveRequest) {
            return;
        }

        router.delete(`/attendance/leave-requests/${currentLeaveRequest.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentLeaveRequest(null);
            },
        });
    };

    const approve = (leaveRequest: LeaveRequest) => {
        router.put(`/attendance/leave-requests/${leaveRequest.id}/approve`, {}, {
            preserveScroll: true,
            onError: () => toast.error('Failed to approve leave request. Please try again.'),
        });
    };

    const submit = () => {
        if (!form.data.employee_id) {
            form.setError('employee_id', 'Employee is required.');

            return;
        }

        if (!form.data.leave_type_id) {
            form.setError('leave_type_id', 'Leave type is required.');

            return;
        }

        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => setIsSheetOpen(false),
        };

        if (currentLeaveRequest) {
            form.put(`/attendance/leave-requests/${currentLeaveRequest.id}`, options);

            return;
        }

        form.post('/attendance/leave-requests', options);
    };

    const handleFiltersChange = (next: LeaveRequestFilters) => {
        list.applyFilters(next);
    };

    return (
        <Main>
            <PageHeader
                title="Leave requests"
                description="Manage employee leave requests and approvals."
                right={
                    can.create ? (
                        <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                            <Plus className="mr-2 h-4 w-4" />
                            Add Leave Request
                        </Button>
                    ) : null
                }
            />

            {/* Status Counts Filter Cards */}
            <div className="mb-6 grid grid-cols-2 sm:grid-cols-5 gap-4">
                {[
                    { value: '', label: 'All Requests', count: status_counts.all, color: 'bg-primary', activeClass: 'border-primary/30 bg-primary/5 ring-primary/20 text-primary' },
                    { value: 'pending', label: 'Pending', count: status_counts.pending, color: 'bg-amber-500', activeClass: 'border-amber-500/30 bg-amber-500/5 ring-amber-500/20 text-amber-500' },
                    { value: 'approved', label: 'Approved', count: status_counts.approved, color: 'bg-emerald-500', activeClass: 'border-emerald-500/30 bg-emerald-500/5 ring-emerald-500/20 text-emerald-500' },
                    { value: 'rejected', label: 'Rejected', count: status_counts.rejected, color: 'bg-red-500', activeClass: 'border-red-500/30 bg-red-500/5 ring-red-500/20 text-red-500' },
                    { value: 'cancelled', label: 'Cancelled', count: status_counts.cancelled, color: 'bg-muted-foreground', activeClass: 'border-border bg-muted/20 ring-border/40 text-muted-foreground' }
                ].map((opt) => {
                    const isActive = filters.status === opt.value;
                    return (
                        <button
                            key={opt.value}
                            type="button"
                            onClick={() => list.applyFilters({ status: opt.value })}
                            className={cn(
                                'glass-card group relative overflow-hidden rounded-2xl border p-4 text-left transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md cursor-pointer',
                                isActive 
                                    ? cn('border-border/80 shadow-xs ring-1', opt.activeClass) 
                                    : 'border-border/60 bg-card/80 hover:border-border hover:bg-card dark:hover:border-white/10'
                            )}
                        >
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70 group-hover:text-foreground transition-colors">
                                    {opt.label}
                                </span>
                                <span className={cn('h-2 w-2 rounded-full shrink-0', opt.color)} />
                            </div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className="text-2xl font-extrabold tracking-tight tabular-nums text-foreground">
                                    {opt.count}
                                </span>
                            </div>
                        </button>
                    );
                })}
            </div>

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
                    <ViewToggle value={view} onChange={setView} />
                    <Button
                        type="button"
                        variant="secondary"
                        className="glass-card h-12 rounded-xl px-5 hover:bg-accent"
                        onClick={() => setIsFiltersOpen(true)}
                    >
                        <Filter className="mr-2 h-4 w-4" />
                        Filters
                        {activeFiltersCount ? (
                            <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                {activeFiltersCount}
                            </span>
                        ) : null}
                    </Button>
                </div>
            </div>

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {leave_requests.map((leaveRequest) => (
                        <LeaveRequestCard
                            key={leaveRequest.id}
                            leaveRequest={leaveRequest}
                            can={can}
                            onEdit={handleEdit}
                            onDelete={handleDelete}
                            onApprove={approve}
                            onReject={handleReject}
                            onCancel={handleCancel}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[1100px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Employee</DataTableHead>
                            <DataTableHead>Type</DataTableHead>
                            <DataTableHead>Start</DataTableHead>
                            <DataTableHead>End</DataTableHead>
                            <DataTableHead>Days</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {leave_requests.map((leaveRequest) => (
                            <TableRow key={leaveRequest.id} className={dataTableBodyRowClass()}>
                                <TableCell className={dataTableCellPrimaryClass()}>{leaveRequest.employee?.name ?? '—'}</TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {leaveRequest.leave_type ? (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] uppercase font-bold tracking-wider flex items-center gap-1"
                                            style={{
                                                borderColor: `${leaveRequest.leave_type.color || '#94a3b8'}40`,
                                                backgroundColor: `${leaveRequest.leave_type.color || '#94a3b8'}15`,
                                                color: leaveRequest.leave_type.color || '#94a3b8',
                                            }}
                                        >
                                            <span
                                                className="inline-block h-2.5 w-2.5 shrink-0 rounded-full border border-black/10 dark:border-white/10"
                                                style={{ backgroundColor: leaveRequest.leave_type.color ?? '#94a3b8' }}
                                            />
                                            {leaveRequest.leave_type.code}
                                        </Badge>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>{formatDisplayDate(leaveRequest.start_date)}</TableCell>
                                <TableCell className={dataTableCellClass()}>{formatDisplayDate(leaveRequest.end_date)}</TableCell>
                                <TableCell className={dataTableCellClass()}>{leaveRequest.total_days}</TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <LeaveRequestStatusBadge status={leaveRequest.status} />
                                </TableCell>
                                <TableCell className={dataTableActionsCellClass()}>
                                    <LeaveRequestRowActions
                                        leaveRequest={leaveRequest}
                                        can={can}
                                        onEdit={handleEdit}
                                        onDelete={handleDelete}
                                        onApprove={approve}
                                        onReject={handleReject}
                                        onCancel={handleCancel}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {leave_requests.length === 0 ? <EmptyState title="No leave requests found." /> : null}

            <Pagination {...list.paginationProps} label="leave requests" />

            <LeaveRequestFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                leaveRequest={currentLeaveRequest}
                employees={employees}
                leaveTypes={leave_types}
                canApprove={can.approve}
                linkedEmployeeId={linkedEmployeeId}
                form={form}
                onSubmit={submit}
            />

            <LeaveRequestFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                employees={employees}
                leaveTypes={leave_types}
                showEmployeeFilter={can.approve}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ status: filters.status, employee_id: '', leave_type_id: '' })}
            />

            <LeaveRequestDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                leaveRequest={currentLeaveRequest}
                onConfirm={confirmDelete}
            />

            <LeaveRequestRejectDialog
                open={isRejectOpen}
                onOpenChange={setIsRejectOpen}
                leaveRequest={currentLeaveRequest}
                onSuccess={() => setCurrentLeaveRequest(null)}
            />

            <LeaveRequestCancelDialog
                open={isCancelOpen}
                onOpenChange={setIsCancelOpen}
                leaveRequest={currentLeaveRequest}
                onSuccess={() => setCurrentLeaveRequest(null)}
            />
        </Main>
    );
}
