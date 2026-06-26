import { router, useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
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
import { ExportMenu } from '@/components/export-menu';
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
    const [view, setView] = useViewPreference('users:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentUser, setCurrentUser] = useState<User | null>(null);

    const filters: UserFilters = {
        status: (initialFilters.status as any) ?? '',
        role_id: initialFilters.role_id ?? '',
    };

    const activeFiltersCount = [initialFilters.status, initialFilters.role_id].filter(Boolean).length;

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
        setCurrentUser(null);
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
        setIsSheetOpen(true);
    };

    const handleEdit = (user: User) => {
        setCurrentUser(user);
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
        setIsSheetOpen(true);
    };

    const handleDelete = (user: User) => {
        setCurrentUser(user);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentUser) {
return;
}

        router.delete(`/organization/users/${currentUser.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentUser(null);
            },
        });
    };

    const toggleStatus = (user: User, enabled: boolean) => {
        router.put(
            `/organization/users/${user.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const submit = () => {
        if (currentUser) {
            form.put(`/organization/users/${currentUser.id}`, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/users', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: UserFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (initialSearch) {
params.set('search', initialSearch);
}

        if (initialFilters.status) {
params.set('status', initialFilters.status);
}

        params.set('format', format);

        return `/organization/users/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Users"
                description="Manage users, roles, and access."
                right={
                    <>
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                        <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                            <Plus className="mr-2 h-4 w-4" />
                            Add User
                        </Button>
                    </>
                }
            />

            <SearchBar
                placeholder="Search users by name, email, company, or role..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={
                    <>
                        <ViewToggle value={view} onChange={setView} />
                        <Button
                            type="button"
                            variant="secondary"
                            className="glass-card rounded-xl h-12 px-5 hover:bg-accent"
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
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {users.map((u) => (
                        <UserCard key={u.id} user={u} onEdit={handleEdit} onDelete={handleDelete} onToggleStatus={toggleStatus} />
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
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                            <TableBody>
                                {users.map((u) => (
                                    <TableRow
                                        key={u.id}
                                        className={dataTableBodyRowClass()}
                                        onClick={() => router.visit(`/organization/users/${u.id}`)}
                                    >
                                        <TableCell className={dataTableCellPrimaryClass()}>{u.name}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{u.email}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{u.role?.name ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <div className="flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
                                                <Switch checked={u.status === 'active'} onCheckedChange={(checked) => toggleStatus(u, checked)} />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {u.status ?? '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className={dataTableActionsCellClass()}>
                                            <ListTableCrudActions
                                                viewHref={`/organization/users/${u.id}`}
                                                onEdit={(e) => {
                                                    e.stopPropagation();
                                                    handleEdit(u);
                                                }}
                                                onDelete={(e) => {
                                                    e.stopPropagation();
                                                    handleDelete(u);
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                </OrganizationDataTable>
            )}

            {users.length === 0 ? <EmptyState title="No users found." /> : null}

            <Pagination {...list.paginationProps} label="users" />

            <UserFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                user={currentUser}
                roles={roles}
                employeesForLinking={employeesForLinking}
                form={form}
                onSubmit={submit}
            />

            <UserFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ status: '', role_id: '' })}
                roles={roles}
            />

            <UserDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} user={currentUser} onConfirm={confirmDelete} />
        </Main>
    );
}
