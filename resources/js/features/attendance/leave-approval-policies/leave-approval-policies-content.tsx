import { Link, router, useForm } from '@inertiajs/react';
import { Plus, Settings2, Star } from 'lucide-react';
import { useState } from 'react';
import {
    destroy,
    index,
    setDefault as setDefaultAction,
    store,
    update,
    updateStatus,
} from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalPolicyController';
import { edit as leaveApprovalSettings } from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalSettingController';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { LeaveApprovalPolicyCard } from './components/leave-approval-policy-card';
import { LeaveApprovalPolicyDeleteDialog } from './components/leave-approval-policy-delete-dialog';
import { LeaveApprovalPolicyFormSheet } from './components/leave-approval-policy-form-sheet';
import {
    defaultLeaveApprovalPolicyFormData,
    leaveApprovalPolicyToFormData,
} from './types';
import type {
    LeaveApprovalApproverTypeOption,
    LeaveApprovalPolicy,
    LeaveApprovalPolicyEmployeeOption,
    LeaveApprovalPolicyPermissions,
} from './types';

export function LeaveApprovalPoliciesContent({
    policies,
    pagination,
    search: initialSearch,
    approver_types,
    employees,
    can,
}: {
    policies: LeaveApprovalPolicy[];
    pagination: PaginationMeta;
    search: string;
    approver_types: LeaveApprovalApproverTypeOption[];
    employees: LeaveApprovalPolicyEmployeeOption[];
    can: LeaveApprovalPolicyPermissions;
}) {
    const list = useServerPaginationFilters({
        url: index.url(),
        search: initialSearch,
        filters: {},
        pagination,
    });
    const [view, setView] = useViewPreference(
        'attendance-leave-approval-policies:view',
        'grid',
    );
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentPolicy, setCurrentPolicy] =
        useState<LeaveApprovalPolicy | null>(null);

    const form = useForm(defaultLeaveApprovalPolicyFormData());

    const handleAdd = () => {
        setCurrentPolicy(null);
        form.reset();
        form.clearErrors();
        form.setData(defaultLeaveApprovalPolicyFormData());
        setIsSheetOpen(true);
    };

    const handleEdit = (policy: LeaveApprovalPolicy) => {
        setCurrentPolicy(policy);
        form.reset();
        form.clearErrors();
        form.setData(leaveApprovalPolicyToFormData(policy));
        setIsSheetOpen(true);
    };

    const handleDelete = (policy: LeaveApprovalPolicy) => {
        setCurrentPolicy(policy);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentPolicy) {
            return;
        }

        router.delete(destroy.url(currentPolicy.id), {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentPolicy(null);
            },
        });
    };

    const toggleStatus = (policy: LeaveApprovalPolicy, enabled: boolean) => {
        router.put(
            updateStatus.url(policy.id),
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const setDefault = (policy: LeaveApprovalPolicy) => {
        router.put(
            setDefaultAction.url(policy.id),
            {},
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(
                        'Failed to set default policy. Please try again.',
                    ),
            },
        );
    };

    const moveStep = (index: number, direction: 'up' | 'down') => {
        const steps = [...form.data.steps];
        const swapWith = direction === 'up' ? index - 1 : index + 1;

        if (swapWith < 0 || swapWith >= steps.length) {
            return;
        }

        // Draft-only reorder — persist only when the user saves the policy form.
        const step = steps[index];
        const neighbor = steps[swapWith];
        steps[index] = neighbor;
        steps[swapWith] = step;
        form.setData('steps', steps);
    };

    const submit = () => {
        const payload = {
            name: form.data.name,
            description: form.data.description,
            is_default: form.data.is_default,
            status: form.data.status,
            steps: form.data.steps.map(
                ({ id, approver_type, approver_employee_id, is_required }) => {
                    const step: {
                        id?: number;
                        approver_type: string;
                        approver_employee_id: number | null;
                        is_required: boolean;
                    } = {
                        approver_type,
                        approver_employee_id:
                            approver_employee_id === ''
                                ? null
                                : Number(approver_employee_id),
                        is_required,
                    };

                    // Preserve step identity on update only; create rejects step IDs.
                    if (currentPolicy && id != null) {
                        step.id = id;
                    }

                    return step;
                },
            ),
        };

        form.transform(() => payload);

        if (currentPolicy) {
            form.put(update.url(currentPolicy.id), {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
                onFinish: () => form.transform((data) => data),
            });

            return;
        }

        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Approval policies"
                description="Configure multi-step leave approval chains for departments."
                right={
                    <div className="flex flex-wrap items-center gap-2">
                        {can.manage_settings ? (
                            <Button
                                variant="secondary"
                                className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                                asChild
                            >
                                <Link href={leaveApprovalSettings.url()}>
                                    <Settings2 className="mr-2 h-4 w-4" />
                                    Settings
                                </Link>
                            </Button>
                        ) : null}
                        {can.create ? (
                            <Button
                                onClick={handleAdd}
                                className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add policy
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <SearchBar
                placeholder="Search policies by name or description..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={<ViewToggle value={view} onChange={setView} />}
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {policies.map((policy) => (
                        <LeaveApprovalPolicyCard
                            key={policy.id}
                            policy={policy}
                            onEdit={handleEdit}
                            onDelete={handleDelete}
                            onToggleStatus={toggleStatus}
                            onSetDefault={setDefault}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Name</DataTableHead>
                            <DataTableHead>Steps</DataTableHead>
                            <DataTableHead>Departments</DataTableHead>
                            <DataTableHead>Default</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {policies.map((policy) => (
                            <TableRow
                                key={policy.id}
                                className={dataTableBodyRowClass()}
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div className="space-y-1">
                                        <div>{policy.name}</div>
                                        {policy.description?.trim() ? (
                                            <div className="line-clamp-1 text-xs font-medium text-muted-foreground">
                                                {policy.description}
                                            </div>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {policy.steps.length}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {policy.departments_count}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {policy.is_default ? (
                                        <Badge
                                            variant="secondary"
                                            className="border-amber-500/20 bg-amber-500/10 text-[10px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-200"
                                        >
                                            Default
                                        </Badge>
                                    ) : policy.can_set_default === true ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-8 rounded-lg px-2"
                                            onClick={() => setDefault(policy)}
                                        >
                                            <Star className="mr-1.5 h-3.5 w-3.5" />
                                            Set default
                                        </Button>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="flex items-center gap-3">
                                        <Switch
                                            checked={policy.status === 'active'}
                                            disabled={
                                                policy.can_change_status !==
                                                    true || policy.is_default
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleStatus(policy, checked)
                                            }
                                        />
                                        <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            {policy.status}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <ListTableCrudActions
                                        onEdit={
                                            policy.can_edit === true
                                                ? () => handleEdit(policy)
                                                : undefined
                                        }
                                        onDelete={
                                            policy.can_delete === true
                                                ? () => handleDelete(policy)
                                                : undefined
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {policies.length === 0 ? (
                <EmptyState title="No approval policies found." />
            ) : null}

            <Pagination {...list.paginationProps} label="policies" />

            <LeaveApprovalPolicyFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                policy={currentPolicy}
                form={form}
                approverTypes={approver_types}
                employees={employees}
                onSubmit={submit}
                onMoveStep={moveStep}
            />

            <LeaveApprovalPolicyDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                policy={currentPolicy}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
