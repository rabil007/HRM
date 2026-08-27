import { router, useForm } from '@inertiajs/react';
import { Plus, Mail, Shield, KeyRound, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';

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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useHasPermission } from '@/hooks/use-has-permission';
import { useOrganizationCrudList } from '@/hooks/use-organization-crud-list';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { buildListExportUrl } from '@/lib/build-list-export-url';
import { toast } from '@/lib/toast';
import { resend as resendInvitation, destroy as destroyInvitation } from '@/routes/organization/user-invitations';
import { passwordReset, revokeSessions } from '@/routes/organization/users/security';
import type { PaginationMeta } from '@/types/pagination';
import { UserCard } from './components/user-card';
import { UserDeleteDialog } from './components/user-delete-dialog';
import { UserFiltersSheet } from './components/user-filters-sheet';
import type { UserFilters } from './components/user-filters-sheet';
import { UserFormSheet } from './components/user-form-sheet';
import { UserInvitationSheet } from './components/user-invitation-sheet';
import type { UserInvitation } from './types';
import type { EmployeeForLinking, User, UserFormData } from './types';

export function UsersContent({
    users,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    roles,
    summary,
    invitations,
    employeesForLinking,
}: {
    users: User[];
    pagination: PaginationMeta;
    search: string;
    filters: { status: string; role_id: string; presence: string };
    roles: { id: number; name: string }[];
    summary: { total: number; online: number; never: number; pending_invites: number };
    invitations: UserInvitation[];
    employeesForLinking: EmployeeForLinking[];
}) {
    const canCreate = useHasPermission('users.create');
    const [isInviteOpen, setIsInviteOpen] = useState(false);
    const canUpdate = useHasPermission('users.update');
    const canDelete = useHasPermission('users.delete');
    const canExport = useHasPermission('users.export');
    const canPasswordReset = useHasPermission('users.password_reset');
    const canRevokeSessions = useHasPermission('users.sessions.revoke');
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
        presence: (initialFilters.presence as UserFilters['presence']) ?? '',
    };

    const activeFiltersCount = [
        initialFilters.status,
        initialFilters.role_id,
        initialFilters.presence,
    ].filter(Boolean).length;

    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['users', 'summary'] });
        }, 60000);

        return () => clearInterval(interval);
    }, []);

    const form = useForm<UserFormData>({
        name: '',
        email: '',
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
                    {canExport ? (
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                    ) : null}
                    {canCreate ? (
                        <>
                            <Button
                                onClick={() => setIsInviteOpen(true)}
                                variant="outline"
                                className="h-12 rounded-xl px-6 bg-background/50 border-primary/20 hover:bg-primary/5"
                            >
                                <Mail className="mr-2 h-4 w-4" />
                                Invite User
                            </Button>
                            <Button
                                onClick={handleAdd}
                                className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add User
                            </Button>
                        </>
                    ) : null}
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
            <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="glass-card flex flex-col justify-between rounded-2xl p-5">
                    <h3 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">Total Users</h3>
                    <p className="mt-2 text-3xl font-bold tracking-tight">{summary.total}</p>
                </div>
                <div className="glass-card flex flex-col justify-between rounded-2xl p-5">
                    <h3 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">Online Now</h3>
                    <p className="mt-2 text-3xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">{summary.online}</p>
                </div>
                <div className="glass-card flex flex-col justify-between rounded-2xl p-5">
                    <h3 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">Never Logged In</h3>
                    <p className="mt-2 text-3xl font-bold tracking-tight text-amber-600 dark:text-amber-500">{summary.never}</p>
                </div>
                <div className="glass-card flex flex-col justify-between rounded-2xl p-5 opacity-50">
                    <h3 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">Pending Invites</h3>
                    <p className="mt-2 text-3xl font-bold tracking-tight">{summary.pending_invites}</p>
                </div>
            </div>

            {crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {users.map((user) => (
                        <UserCard
                            key={user.id}
                            user={user}
                            onEdit={canUpdate ? handleEdit : undefined}
                            onDelete={canDelete ? crud.openDelete : undefined}
                            onToggleStatus={
                                canUpdate ? toggleStatus : undefined
                            }
                            canPasswordReset={canPasswordReset}
                            canRevokeSessions={canRevokeSessions}
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
                            <DataTableHead>Presence</DataTableHead>
                            <DataTableHead>2FA</DataTableHead>
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
                                        {canUpdate ? (
                                            <Switch
                                                checked={
                                                    user.status === 'active'
                                                }
                                                onCheckedChange={(checked) =>
                                                    toggleStatus(user, checked)
                                                }
                                            />
                                        ) : null}
                                            <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                                {user.status ?? '—'}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="flex items-center gap-2">
                                            <div className={`h-2 w-2 rounded-full ${user.presence === 'online' ? 'bg-emerald-500' : user.presence === 'recent' ? 'bg-amber-500' : 'bg-muted-foreground/30'}`} />
                                            <span className="text-sm font-medium">
                                                {user.presence === 'online' ? 'Online' : user.presence === 'recent' ? 'Recently active' : user.presence === 'offline' ? 'Offline' : 'Never'}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {user.two_factor_enabled ? (
                                            <span className="text-emerald-600 dark:text-emerald-400 font-medium">Enabled</span>
                                        ) : (
                                            <span className="text-muted-foreground">Disabled</span>
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={dataTableActionsCellClass()}
                                    >
                                    <div className="flex items-center justify-end gap-1">
                                        {canUpdate && (canPasswordReset || canRevokeSessions) ? (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                                                        title="Security Actions"
                                                    >
                                                        <Shield className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-48">
                                                    {canPasswordReset && (
                                                        <DropdownMenuItem
                                                            onClick={(e) => {
                                                                e.stopPropagation();

                                                                if (confirm('Send a password reset link to this user?')) {
                                                                    router.post(passwordReset.url({ user: user.id }), {}, { preserveScroll: true });
                                                                }
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <KeyRound className="mr-2 h-4 w-4" />
                                                            <span>Reset Password</span>
                                                        </DropdownMenuItem>
                                                    )}
                                                    {canRevokeSessions && (
                                                        <DropdownMenuItem
                                                            onClick={(e) => {
                                                                e.stopPropagation();

                                                                if (confirm('Revoke all active sessions for this user?')) {
                                                                    router.post(revokeSessions.url({ user: user.id }), {}, { preserveScroll: true });
                                                                }
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <LogOut className="mr-2 h-4 w-4" />
                                                            <span>Revoke Sessions</span>
                                                        </DropdownMenuItem>
                                                    )}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        ) : null}
                                        <ListTableCrudActions
                                            viewHref={`/organization/users/${user.id}`}
                                            onEdit={
                                                canUpdate
                                                    ? (event) => {
                                                          event.stopPropagation();
                                                          handleEdit(user);
                                                      }
                                                    : undefined
                                            }
                                            onDelete={
                                                canDelete
                                                    ? (event) => {
                                                          event.stopPropagation();
                                                          crud.openDelete(user);
                                                      }
                                                    : undefined
                                            }
                                            showEdit={canUpdate}
                                            showDelete={canDelete}
                                        />
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {users.length === 0 ? <EmptyState title="No users found." /> : null}

            {invitations.length > 0 ? (
                <div className="mt-12">
                    <h2 className="mb-4 text-xl font-bold tracking-tight">Pending Invitations</h2>
                    <OrganizationDataTable minWidth="min-w-[800px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="pl-5">Email</DataTableHead>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Role</DataTableHead>
                                <DataTableHead>Expires</DataTableHead>
                                <DataTableHead className="text-right">Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {invitations.map((invitation) => (
                                <TableRow key={invitation.id} className={dataTableBodyRowClass()}>
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        {invitation.email}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {invitation.name || <span className="text-muted-foreground italic">None</span>}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {invitation.role?.name || '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {new Date(invitation.expires_at).toLocaleDateString()}
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <div className="flex justify-end gap-2">
                                            {canCreate ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        router.post(resendInvitation.url({ invitation: invitation.id }), {}, {
                                                            preserveScroll: true,
                                                        });
                                                    }}
                                                >
                                                    Resend
                                                </Button>
                                            ) : null}
                                            {canDelete ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-destructive hover:bg-destructive hover:text-destructive-foreground"
                                                    onClick={(e) => {
                                                        e.stopPropagation();

                                                        if (confirm('Are you sure you want to revoke this invitation?')) {
                                                            router.delete(destroyInvitation.url({ invitation: invitation.id }), {
                                                                preserveScroll: true,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    Revoke
                                                </Button>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>
                </div>
            ) : null}

            <UserInvitationSheet
                open={isInviteOpen}
                onOpenChange={setIsInviteOpen}
                roles={roles}
            />

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
                onReset={() => handleFiltersChange({ status: '', role_id: '', presence: '' })}
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
