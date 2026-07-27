import { router, useForm } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
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
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useOrganizationCrudList } from '@/hooks/use-organization-crud-list';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { buildListExportUrl } from '@/lib/build-list-export-url';
import type { PaginationMeta } from '@/types/pagination';
import { RoleCard } from './components/role-card';
import { RoleDeleteDialog } from './components/role-delete-dialog';
import { RoleFiltersSheet } from './components/role-filters-sheet';
import type { RoleFilters } from './components/role-filters-sheet';
import { RoleFormSheet } from './components/role-form-sheet';
import type { Company, Role, RoleFormData } from './types';

export function RolesContent({
    roles,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    company,
    permissions: _permissions,
}: {
    roles: Role[];
    pagination: PaginationMeta;
    search: string;
    filters: { has_permissions: string };
    company: Company | null;
    permissions: { id: number; name: string }[];
}) {
    void _permissions;

    const list = useServerPaginationFilters({
        url: '/organization/roles',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const crud = useOrganizationCrudList<Role>({ viewKey: 'roles:view' });

    const filters: RoleFilters = {
        has_permissions:
            initialFilters.has_permissions as RoleFilters['has_permissions'],
    };

    const activeFiltersCount = [initialFilters.has_permissions].filter(
        Boolean,
    ).length;

    const form = useForm<RoleFormData>({
        name: '',
    });

    const handleAdd = () => {
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({ name: '' });
        });
    };

    const handleEdit = (role: Role) => {
        crud.openEdit(role, () => {
            form.reset();
            form.clearErrors();
            form.setData({ name: role.name ?? '' });
        });
    };

    const confirmDelete = () => {
        if (!crud.currentEntity) {
            return;
        }

        router.delete(`/organization/roles/${crud.currentEntity.id}`, {
            onFinish: () => crud.confirmDeleteFinish(),
        });
    };

    const submit = () => {
        if (crud.currentEntity) {
            form.put(`/organization/roles/${crud.currentEntity.id}`, {
                preserveScroll: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/roles', {
            preserveScroll: true,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: RoleFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') =>
        buildListExportUrl('/organization/roles/export', {
            search: initialSearch,
            has_permissions: initialFilters.has_permissions,
            format,
        });

    return (
        <OrganizationListPageShell
            title="Roles & Permissions"
            description={
                company?.name
                    ? `Manage roles for ${company.name}.`
                    : 'Manage roles and permissions.'
            }
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
                        Add Role
                    </Button>
                </>
            }
            search={{
                placeholder:
                    'Search roles by name, slug, company, or permission...',
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
            pagination={
                <Pagination {...list.paginationProps} label="roles" />
            }
        >
            {crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {roles.map((role) => (
                        <RoleCard
                            key={role.id}
                            role={role}
                            onEdit={handleEdit}
                            onDelete={crud.openDelete}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Role</DataTableHead>
                            <DataTableHead>Permissions</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {roles.map((role) => (
                            <TableRow
                                key={role.id}
                                className={dataTableBodyRowClass()}
                                onClick={() =>
                                    router.visit(
                                        `/organization/roles/${role.id}`,
                                    )
                                }
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    {role.name}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {role.permissions.length
                                        ? role.permissions
                                              .slice(0, 4)
                                              .join(', ')
                                        : '—'}
                                    {role.permissions.length > 4
                                        ? ` (+${role.permissions.length - 4} more)`
                                        : ''}
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <div className="flex items-center justify-end gap-1">
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="icon"
                                            className="h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground dark:hover:bg-white/10 dark:hover:text-zinc-100"
                                            title="View Assigned Users"
                                        >
                                            <a
                                                href={`/organization/users?role_id=${role.id}`}
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <Users className="h-4 w-4" />
                                            </a>
                                        </Button>
                                        <ListTableCrudActions
                                            viewHref={`/organization/roles/${role.id}`}
                                            onEdit={(e) => {
                                                e.stopPropagation();
                                                handleEdit(role);
                                            }}
                                            onDelete={(e) => {
                                                e.stopPropagation();
                                                crud.openDelete(role);
                                            }}
                                        />
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {roles.length === 0 ? (
                <EmptyState title="No roles found." />
            ) : null}

            <RoleFormSheet
                open={crud.isSheetOpen}
                onOpenChange={crud.setIsSheetOpen}
                role={crud.currentEntity}
                form={form}
                onSubmit={submit}
            />

            <RoleFiltersSheet
                open={crud.isFiltersOpen}
                onOpenChange={crud.setIsFiltersOpen}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ has_permissions: '' })}
            />

            <RoleDeleteDialog
                open={crud.isDeleteDialogOpen}
                onOpenChange={crud.setIsDeleteDialogOpen}
                role={crud.currentEntity}
                onConfirm={confirmDelete}
            />
        </OrganizationListPageShell>
    );
}
