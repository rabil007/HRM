import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { ExportMenu } from '@/components/export-menu';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { OrganizationListPageShell } from '@/components/organization-list-page-shell';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useOrganizationCrudList } from '@/hooks/use-organization-crud-list';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { buildListExportUrl } from '@/lib/build-list-export-url';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { DepartmentCard } from './components/department-card';
import { DepartmentDeleteDialog } from './components/department-delete-dialog';
import { DepartmentFiltersSheet } from './components/department-filters-sheet';
import type { DepartmentFilters } from './components/department-filters-sheet';
import { DepartmentFormSheet } from './components/department-form-sheet';
import { DepartmentTreeView } from './components/department-tree-view';
import type {
    Branch,
    Department,
    DepartmentFormData,
    DepartmentParentOption,
    LeaveApprovalPolicyOption,
    Manager,
} from './types';

export function DepartmentsContent({
    departments,
    all_departments,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    branches,
    parents,
    managers,
    leave_approval_policies = [],
}: {
    departments: Department[];
    all_departments: any[];
    pagination: PaginationMeta;
    search: string;
    filters: {
        id?: string;
        branch_id: string;
        parent_id: string;
        manager_id: string;
        status: string;
        code: string;
    };
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
    leave_approval_policies?: LeaveApprovalPolicyOption[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/departments',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const crud = useOrganizationCrudList<Department>({
        viewKey: 'departments:view',
    });

    const filters: DepartmentFilters = {
        id: initialFilters.id ?? '',
        branch_id: initialFilters.branch_id,
        parent_id: initialFilters.parent_id,
        manager_id: initialFilters.manager_id,
        status: initialFilters.status as DepartmentFilters['status'],
        code: initialFilters.code,
    };

    const activeFiltersCount = [
        initialFilters.id,
        initialFilters.branch_id,
        initialFilters.parent_id,
        initialFilters.manager_id,
        initialFilters.status,
        initialFilters.code.trim(),
    ].filter(Boolean).length;

    const form = useForm<DepartmentFormData>({
        branch_id: '',
        parent_id: '',
        manager_id: '',
        leave_approval_policy_id: '',
        name: '',
        code: '',
        status: 'active',
    });

    const handleAdd = () => {
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({
                branch_id: '',
                parent_id: '',
                manager_id: '',
                leave_approval_policy_id: '',
                name: '',
                code: '',
                status: 'active',
            });
        });
    };

    const handleEdit = (department: Department) => {
        crud.openEdit(department, () => {
            form.reset();
            form.clearErrors();
            form.setData({
                branch_id: department.branch?.id ?? '',
                parent_id: department.parent?.id ?? '',
                manager_id:
                    department.manager_assignment?.type === 'direct'
                        ? (department.manager?.id ?? '')
                        : '',
                leave_approval_policy_id:
                    department.leave_approval_policy_id ?? '',
                name: department.name ?? '',
                code: department.code ?? '',
                status: department.status ?? 'active',
            });
        });
    };

    const confirmDelete = () => {
        if (!crud.currentEntity) {
            return;
        }

        router.delete(`/organization/departments/${crud.currentEntity.id}`, {
            onFinish: () => crud.confirmDeleteFinish(),
        });
    };

    const toggleStatus = (department: Department, enabled: boolean) => {
        router.put(
            `/organization/departments/${department.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const submit = () => {
        if (crud.currentEntity) {
            form.put(`/organization/departments/${crud.currentEntity.id}`, {
                preserveScroll: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/departments', {
            preserveScroll: true,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: DepartmentFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') =>
        buildListExportUrl('/organization/departments/export', {
            search: initialSearch,
            id: initialFilters.id,
            branch_id: initialFilters.branch_id,
            parent_id: initialFilters.parent_id,
            manager_id: initialFilters.manager_id,
            status: initialFilters.status,
            code: initialFilters.code,
            format,
        });

    return (
        <OrganizationListPageShell
            title="Departments"
            description="Manage departments across your organization."
            headerRight={
                <>
                    <ExportMenu
                        getUrl={getExportUrl}
                        buttonVariant="secondary"
                        buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                    />
                    <Button
                        onClick={handleAdd}
                        className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                    >
                        <Plus className="mr-2 h-4 w-4" />
                        Add Department
                    </Button>
                </>
            }
            search={{
                placeholder:
                    'Search departments by name, code, company, branch, or manager...',
                value: list.searchInput,
                onChange: list.onSearchChange,
                right:
                    crud.view && crud.setView ? (
                        <ViewToggle
                            value={crud.view}
                            onChange={crud.setView}
                            showTreeView={true}
                        />
                    ) : null,
            }}
            filtersButton={{
                onClick: () => crud.setIsFiltersOpen(true),
                activeFiltersCount,
            }}
            pagination={
                crud.view !== 'tree' ? (
                    <Pagination {...list.paginationProps} label="departments" />
                ) : null
            }
        >
            {crud.view === 'tree' ? (
                <DepartmentTreeView departments={all_departments} />
            ) : crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {departments.map((department) => (
                        <DepartmentCard
                            key={department.id}
                            department={department}
                            onEdit={handleEdit}
                            onDelete={crud.openDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[1100px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">
                                Department
                            </DataTableHead>
                            <DataTableHead>Code</DataTableHead>
                            <DataTableHead>Branch</DataTableHead>
                            <DataTableHead>Parent</DataTableHead>
                            <DataTableHead>Manager</DataTableHead>
                            <DataTableHead>Approval policy</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {departments.map((department) => (
                            <TableRow
                                key={department.id}
                                className={dataTableBodyRowClass()}
                                onClick={() =>
                                    router.visit(
                                        `/organization/departments/${department.id}`,
                                    )
                                }
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    {department.name}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {department.code ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {department.branch?.name ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {department.parent?.name ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="space-y-0.5">
                                        <div>
                                            {department.manager?.name ?? '—'}
                                        </div>
                                        {department.manager_assignment
                                            ?.label ? (
                                            <div className="text-[11px] text-muted-foreground">
                                                {
                                                    department
                                                        .manager_assignment
                                                        .label
                                                }
                                            </div>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="space-y-0.5">
                                        <div>
                                            {department.leave_approval_policy
                                                ?.name ?? '—'}
                                        </div>
                                        {department
                                            .leave_approval_policy_assignment
                                            ?.label ? (
                                            <div className="text-[11px] text-muted-foreground">
                                                {
                                                    department
                                                        .leave_approval_policy_assignment
                                                        .label
                                                }
                                            </div>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div
                                        className="flex items-center gap-3"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Switch
                                            checked={
                                                department.status === 'active'
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleStatus(
                                                    department,
                                                    checked,
                                                )
                                            }
                                        />
                                        <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            {department.status ?? '—'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <ListTableCrudActions
                                        viewHref={`/organization/departments/${department.id}`}
                                        onEdit={(e) => {
                                            e.stopPropagation();
                                            handleEdit(department);
                                        }}
                                        onDelete={(e) => {
                                            e.stopPropagation();
                                            crud.openDelete(department);
                                        }}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {crud.view !== 'tree' && departments.length === 0 ? (
                <EmptyState title="No departments found." />
            ) : null}

            <DepartmentFormSheet
                open={crud.isSheetOpen}
                onOpenChange={crud.setIsSheetOpen}
                department={crud.currentEntity}
                branches={branches}
                parents={parents}
                managers={managers}
                leaveApprovalPolicies={leave_approval_policies}
                form={form}
                onSubmit={submit}
            />

            <DepartmentFiltersSheet
                open={crud.isFiltersOpen}
                onOpenChange={crud.setIsFiltersOpen}
                branches={branches}
                parents={parents}
                managers={managers}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() =>
                    handleFiltersChange({
                        id: '',
                        branch_id: '',
                        parent_id: '',
                        manager_id: '',
                        status: '',
                        code: '',
                    })
                }
            />

            <DepartmentDeleteDialog
                open={crud.isDeleteDialogOpen}
                onOpenChange={crud.setIsDeleteDialogOpen}
                department={crud.currentEntity}
                onConfirm={confirmDelete}
            />
        </OrganizationListPageShell>
    );
}
