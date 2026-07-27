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
import { UserCard } from './components/user-card';
import { UserDeleteDialog } from './components/user-delete-dialog';
import { UserFiltersSheet } from './components/user-filters-sheet';
import type { UserFilters } from './components/user-filters-sheet';
import { UserFormSheet } from './components/user-form-sheet';
import type { EmployeeForLinking, User, UserFormData } from './types';

export function UsersContent({
    users,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    roles,
    employeesForLinking,
}: {
    users: User[];
    pagination: PaginationMeta;
    search: string;
    filters: { status: string; role_id: string };
    roles: { id: number; name: string }[];
    employeesForLinking: EmployeeForLinking[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/users',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const crud = useOrganizationCrudList<User>({ viewKey: 'users:view' });

    const filters: UserFilters = {
        status: (initialFilters.status as UserFilters['status']) ?? '',
        role_id: initialFilters.role_id ?? '',
    };

    const activeFiltersCount = [
        initialFilters.status,
        initialFilters.role_id,
    ].filter(Boolean).length;

    const form = useForm<UserFormData>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        avatar: null,
        use_employee_avatar: false,
        employee_id: '',
        role_id: '',
        status: 'active',
    });

    const handleAdd = () => {
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({
                name: '',
                email: '',
                password: '',
                password_confirmation: '',
                avatar: null,
                use_employee_avatar: false,
                employee_id: '',
                role_id: '',
                status: 'active',
            });
        });
    };

    const handleEdit = (user: User) => {
        crud.openEdit(user, () => {
            form.reset();
            form.clearErrors();
            form.setData({
                name: user.name ?? '',
                email: user.email ?? '',
                password: '',
                password_confirmation: '',
                avatar: null,
                use_employee_avatar: false,
                employee_id: user.linked_employee?.id ?? '',
                role_id: user.role?.id ?? '',
                status: user.status ?? 'active',
            });
        });
    };

    const confirmDelete = () => {
        if (!crud.currentEntity) {
            return;
        }

        router.delete(`/organization/users/${crud.currentEntity.id}`, {
            onFinish: () => crud.confirmDeleteFinish(),
        });
    };

    const toggleStatus = (user: User, enabled: boolean) => {
        router.put(
            `/organization/users/${user.id}/status`,
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
            form.put(`/organization/users/${crud.currentEntity.id}`, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/users', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: UserFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') =>
        buildListExportUrl('/organization/users/export', {
            search: initialSearch,
            status: initialFilters.status,
            format,
        });

    return (
        <OrganizationListPageShell
            title="Users"
            description="Manage users, roles, and access."
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
                        Add User
                    </Button>
                </>
            }
            search={{
                placeholder: 'Search users by name, email, company, or role...',
                value: list.searchInput,
                onChange: list.onSearchChange,
                right:
                    crud.view && crud.setView ? (
                        <ViewToggle value={crud.view} onChange={crud.setView} />
                    ) : null,
            }}
            filtersButton={{
                onClick: () => crud.setIsFiltersOpen(true),
                activeFiltersCount,
            }}
            pagination={<Pagination {...list.paginationProps} label="users" />}
        >
            {crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {users.map((user) => (
                        <UserCard
                            key={user.id}
                            user={user}
                            onEdit={handleEdit}
                            onDelete={crud.openDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">User</DataTableHead>
                            <DataTableHead>Email</DataTableHead>
                            <DataTableHead>Role</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {users.map((user) => (
                            <TableRow
                                key={user.id}
                                className={dataTableBodyRowClass()}
                                onClick={() =>
                                    router.visit(
                                        `/organization/users/${user.id}`,
                                    )
                                }
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    {user.name}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {user.email}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {user.role?.name ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div
                                        className="flex items-center gap-3"
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                    >
                                        <Switch
                                            checked={user.status === 'active'}
                                            onCheckedChange={(checked) =>
                                                toggleStatus(user, checked)
                                            }
                                        />
                                        <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            {user.status ?? '—'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <ListTableCrudActions
                                        viewHref={`/organization/users/${user.id}`}
                                        onEdit={(event) => {
                                            event.stopPropagation();
                                            handleEdit(user);
                                        }}
                                        onDelete={(event) => {
                                            event.stopPropagation();
                                            crud.openDelete(user);
                                        }}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {users.length === 0 ? <EmptyState title="No users found." /> : null}

            <UserFormSheet
                open={crud.isSheetOpen}
                onOpenChange={crud.setIsSheetOpen}
                user={crud.currentEntity}
                roles={roles}
                employeesForLinking={employeesForLinking}
                form={form}
                onSubmit={submit}
            />

            <UserFiltersSheet
                open={crud.isFiltersOpen}
                onOpenChange={crud.setIsFiltersOpen}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ status: '', role_id: '' })}
                roles={roles}
            />

            <UserDeleteDialog
                open={crud.isDeleteDialogOpen}
                onOpenChange={crud.setIsDeleteDialogOpen}
                user={crud.currentEntity}
                onConfirm={confirmDelete}
            />
        </OrganizationListPageShell>
    );
}
