import { router, useForm } from '@inertiajs/react';
import { Filter, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import {
    approve as leaveRequestApprove,
    destroy as leaveRequestDestroy,
    index as leaveRequestIndex,
    store as leaveRequestStore,
    update as leaveRequestUpdate,
} from '@/actions/App/Http/Controllers/Attendance/LeaveRequestController';
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
import { MobileRecordList } from '@/components/mobile-record-list';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SavedViewsControl } from '@/components/saved-views-control';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { formatDisplayDate } from '@/lib/format-date';
import {
    DESKTOP_OPERATIONAL_TABLE_CLASS,
    MOBILE_OPERATIONAL_LIST_CLASS,
} from '@/lib/mobile-operational-list';
import type { SavedView } from '@/lib/saved-views';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { LeaveRequestAdministrativeDeleteDialog } from './components/leave-request-administrative-delete-dialog';
import { LeaveRequestCancelDialog } from './components/leave-request-cancel-dialog';
import { LeaveRequestCard } from './components/leave-request-card';
import { LeaveRequestDeleteDialog } from './components/leave-request-delete-dialog';
import { LeaveRequestFiltersSheet } from './components/leave-request-filters-sheet';
import { LeaveRequestFormSheet } from './components/leave-request-form-sheet';
import { LeaveRequestMobileCard } from './components/leave-request-mobile-card';
import { LeaveRequestRejectDialog } from './components/leave-request-reject-dialog';
import { LeaveRequestRowActions } from './components/leave-request-row-actions';
import { LeaveRequestStatusBadge } from './components/leave-request-status-badge';
import { defaultLeaveRequestFormData, leaveRequestToFormData } from './types';
import type {
    LeaveRequest,
    LeaveRequestEmployeeOption,
    LeaveRequestFilters,
    LeaveRequestPermissions,
    LeaveRequestScope,
    LeaveRequestTypeOption,
} from './types';

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
    saved_views = [],
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
    saved_views?: SavedView[];
}) {
    const list = useServerPaginationFilters({
        url: leaveRequestIndex.url(),
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference(
        'attendance-leave-requests:view',
        'grid',
    );
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isAdministrativeDeleteOpen, setIsAdministrativeDeleteOpen] =
        useState(false);
    const [isRejectOpen, setIsRejectOpen] = useState(false);
    const [isCancelOpen, setIsCancelOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentLeaveRequest, setCurrentLeaveRequest] =
        useState<LeaveRequest | null>(null);

    const filters: LeaveRequestFilters = {
        status: initialFilters.status,
        employee_id: initialFilters.employee_id,
        leave_type_id: initialFilters.leave_type_id,
        scope: initialFilters.scope ?? 'my',
    };

    const activeFiltersCount = [
        initialFilters.employee_id,
        initialFilters.leave_type_id,
    ].filter(Boolean).length;

    const scopeOptions: Array<{
        value: LeaveRequestScope;
        label: string;
    }> = [
        { value: 'my', label: 'My' },
        { value: 'awaiting_my_approval', label: 'Awaiting My Approval' },
        { value: 'assigned_to_me', label: 'Assigned to Me' },
        ...(can.view_all ? ([{ value: 'all', label: 'All' }] as const) : []),
    ];

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

    const handleAdministrativeDelete = (leaveRequest: LeaveRequest) => {
        setCurrentLeaveRequest(leaveRequest);
        setIsAdministrativeDeleteOpen(true);
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

        router.delete(leaveRequestDestroy.url(currentLeaveRequest.id), {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentLeaveRequest(null);
            },
        });
    };

    const approve = (leaveRequest: LeaveRequest) => {
        router.put(
            leaveRequestApprove.url(leaveRequest.id),
            {},
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(
                        'Failed to approve leave request. Please try again.',
                    ),
            },
        );
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
            form.put(leaveRequestUpdate.url(currentLeaveRequest.id), options);

            return;
        }

        form.post(leaveRequestStore.url(), options);
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
                        <Button
                            onClick={handleAdd}
                            className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Add Leave Request
                        </Button>
                    ) : null
                }
            />

            {/* Unified Filter Bar */}
            <div className="glass-card mb-6 overflow-hidden rounded-2xl border border-border/60">
                {/* Row 1: Scope */}
                <div className="flex items-center gap-0 border-b border-border/40 px-1 py-1">
                    <span className="shrink-0 px-3 text-[10px] font-bold tracking-[0.18em] text-muted-foreground/50 uppercase">
                        View
                    </span>
                    <div className="mx-1 h-4 w-px shrink-0 bg-border/50" />
                    <div className="flex flex-wrap gap-1">
                        {scopeOptions.map((opt) => {
                            const isActive = filters.scope === opt.value;

                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() =>
                                        list.applyFilters({ scope: opt.value })
                                    }
                                    className={cn(
                                        'rounded-lg px-3 py-1.5 text-sm font-medium transition-all duration-150',
                                        isActive
                                            ? 'bg-primary text-primary-foreground shadow-sm'
                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                    )}
                                >
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Row 2: Status */}
                <div className="flex items-center gap-0 px-1 py-1">
                    <span className="shrink-0 px-3 text-[10px] font-bold tracking-[0.18em] text-muted-foreground/50 uppercase">
                        Status
                    </span>
                    <div className="mx-1 h-4 w-px shrink-0 bg-border/50" />
                    <div className="flex flex-wrap gap-1">
                        {[
                            {
                                value: '',
                                label: 'All',
                                count: status_counts.all,
                                activeClass: 'bg-primary text-primary-foreground shadow-sm',
                                dotClass: 'bg-primary',
                            },
                            {
                                value: 'pending',
                                label: 'Pending',
                                count: status_counts.pending,
                                activeClass: 'bg-amber-500/15 text-amber-500 ring-1 ring-amber-500/30',
                                dotClass: 'bg-amber-500',
                            },
                            {
                                value: 'approved',
                                label: 'Approved',
                                count: status_counts.approved,
                                activeClass: 'bg-emerald-500/15 text-emerald-500 ring-1 ring-emerald-500/30',
                                dotClass: 'bg-emerald-500',
                            },
                            {
                                value: 'rejected',
                                label: 'Rejected',
                                count: status_counts.rejected,
                                activeClass: 'bg-red-500/15 text-red-500 ring-1 ring-red-500/30',
                                dotClass: 'bg-red-500',
                            },
                            {
                                value: 'cancelled',
                                label: 'Cancelled',
                                count: status_counts.cancelled,
                                activeClass: 'bg-muted/40 text-muted-foreground ring-1 ring-border/60',
                                dotClass: 'bg-muted-foreground',
                            },
                        ].map((opt) => {
                            const isActive = filters.status === opt.value;

                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() =>
                                        list.applyFilters({ status: opt.value })
                                    }
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-all duration-150',
                                        isActive
                                            ? opt.activeClass
                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'h-1.5 w-1.5 shrink-0 rounded-full transition-opacity',
                                            opt.dotClass,
                                            isActive ? 'opacity-100' : 'opacity-50',
                                        )}
                                    />
                                    {opt.label}
                                    <span
                                        className={cn(
                                            'ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums transition-colors',
                                            isActive
                                                ? 'bg-black/10 dark:bg-white/15'
                                                : 'bg-muted/60 text-muted-foreground/70',
                                        )}
                                    >
                                        {opt.count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="mb-8 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search className="absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search by employee..."
                        value={list.searchInput}
                        onChange={(e) => list.onSearchChange(e.target.value)}
                        className="h-12 w-full rounded-xl border-input bg-background/80 pl-10 text-sm dark:border-white/5 dark:bg-white/5"
                    />
                </div>

                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <div className="hidden md:block">
                        <ViewToggle value={view} onChange={setView} />
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
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
                    <SavedViewsControl
                        pageKey="leave"
                        indexUrl={leaveRequestIndex.url()}
                        currentFilters={{
                            search: initialSearch,
                            ...filters,
                        }}
                        views={saved_views}
                    />
                </div>
            </div>

            {leave_requests.length === 0 ? (
                <EmptyState title="No leave requests found." />
            ) : (
                <>
                    <div className={MOBILE_OPERATIONAL_LIST_CLASS}>
                        <MobileRecordList>
                            {leave_requests.map((leaveRequest) => (
                                <LeaveRequestMobileCard
                                    key={leaveRequest.id}
                                    leaveRequest={leaveRequest}
                                    onEdit={handleEdit}
                                    onDelete={handleDelete}
                                    onAdministrativeDelete={
                                        handleAdministrativeDelete
                                    }
                                    onApprove={approve}
                                    onReject={handleReject}
                                    onCancel={handleCancel}
                                />
                            ))}
                        </MobileRecordList>
                    </div>

                    <div className={DESKTOP_OPERATIONAL_TABLE_CLASS}>
                        {view === 'grid' ? (
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                {leave_requests.map((leaveRequest) => (
                                    <LeaveRequestCard
                                        key={leaveRequest.id}
                                        leaveRequest={leaveRequest}
                                        can={can}
                                        onEdit={handleEdit}
                                        onDelete={handleDelete}
                                        onAdministrativeDelete={
                                            handleAdministrativeDelete
                                        }
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
                                        <DataTableHead className="pl-5">
                                            Employee
                                        </DataTableHead>
                                        <DataTableHead>Type</DataTableHead>
                                        <DataTableHead>Start</DataTableHead>
                                        <DataTableHead>End</DataTableHead>
                                        <DataTableHead>Days</DataTableHead>
                                        <DataTableHead>Status</DataTableHead>
                                        <DataTableHead className="text-right">
                                            Actions
                                        </DataTableHead>
                                    </DataTableHeaderRow>
                                </TableHeader>
                                <TableBody>
                                    {leave_requests.map((leaveRequest) => (
                                        <TableRow
                                            key={leaveRequest.id}
                                            className={dataTableBodyRowClass()}
                                        >
                                            <TableCell
                                                className={dataTableCellPrimaryClass()}
                                            >
                                                {leaveRequest.employee?.name ??
                                                    '—'}
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                {leaveRequest.leave_type ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="flex items-center gap-1 text-[10px] font-bold tracking-wider uppercase"
                                                        style={{
                                                            borderColor: `${leaveRequest.leave_type.color || '#94a3b8'}40`,
                                                            backgroundColor: `${leaveRequest.leave_type.color || '#94a3b8'}15`,
                                                            color:
                                                                leaveRequest
                                                                    .leave_type
                                                                    .color ||
                                                                '#94a3b8',
                                                        }}
                                                    >
                                                        <span
                                                            className="inline-block h-2.5 w-2.5 shrink-0 rounded-full border border-black/10 dark:border-white/10"
                                                            style={{
                                                                backgroundColor:
                                                                    leaveRequest
                                                                        .leave_type
                                                                        .color ??
                                                                    '#94a3b8',
                                                            }}
                                                        />
                                                        {
                                                            leaveRequest
                                                                .leave_type.code
                                                        }
                                                    </Badge>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                {formatDisplayDate(
                                                    leaveRequest.start_date,
                                                )}
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                {formatDisplayDate(
                                                    leaveRequest.end_date,
                                                )}
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                {leaveRequest.total_days}
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                <LeaveRequestStatusBadge
                                                    status={leaveRequest.status}
                                                />
                                            </TableCell>
                                            <TableCell
                                                className={dataTableActionsCellClass()}
                                            >
                                                <LeaveRequestRowActions
                                                    leaveRequest={leaveRequest}
                                                    can={can}
                                                    onEdit={handleEdit}
                                                    onDelete={handleDelete}
                                                    onAdministrativeDelete={
                                                        handleAdministrativeDelete
                                                    }
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
                    </div>
                </>
            )}

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
                onReset={() =>
                    handleFiltersChange({
                        status: filters.status,
                        employee_id: '',
                        leave_type_id: '',
                        scope: filters.scope,
                    })
                }
            />

            <LeaveRequestDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                leaveRequest={currentLeaveRequest}
                onConfirm={confirmDelete}
            />

            <LeaveRequestAdministrativeDeleteDialog
                open={isAdministrativeDeleteOpen}
                onOpenChange={setIsAdministrativeDeleteOpen}
                leaveRequest={currentLeaveRequest}
                onSuccess={() => setCurrentLeaveRequest(null)}
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
