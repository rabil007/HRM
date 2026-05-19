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
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
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
    const [view, setView] = useViewPreference('roles:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentRole, setCurrentRole] = useState<Role | null>(null);

    const filters: RoleFilters = {
        has_permissions: initialFilters.has_permissions,
    };

    const activeFiltersCount = [initialFilters.has_permissions].filter(Boolean).length;

    const form = useForm<RoleFormData>({
        name: '',
    });

    const handleAdd = () => {
        setCurrentRole(null);
        form.reset();
        form.clearErrors();
        form.setData({ name: '' });
        setIsSheetOpen(true);
    };

    const handleEdit = (role: Role) => {
        setCurrentRole(role);
        form.reset();
        form.clearErrors();
        form.setData({ name: role.name ?? '' });
        setIsSheetOpen(true);
    };

    const handleDelete = (role: Role) => {
        setCurrentRole(role);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentRole) {
return;
}

        router.delete(`/organization/roles/${currentRole.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentRole(null);
            },
        });
    };

    const submit = () => {
        if (currentRole) {
            form.put(`/organization/roles/${currentRole.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/roles', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: RoleFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (initialSearch) {
params.set('search', initialSearch);
}

        if (initialFilters.has_permissions) {
params.set('has_permissions', initialFilters.has_permissions);
}

        params.set('format', format);

        return `/organization/roles/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Roles & Permissions"
                description={company?.name ? `Manage roles for ${company.name}.` : 'Manage roles and permissions.'}
                right={
                    <>
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                        <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                            <Plus className="mr-2 h-4 w-4" />
                            Add Role
                        </Button>
                    </>
                }
            />

            <SearchBar
                placeholder="Search roles by name, slug, company, or permission..."
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
                    {roles.map((role) => (
                        <RoleCard key={role.id} role={role} onEdit={handleEdit} onDelete={handleDelete} />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Role</DataTableHead>
                            <DataTableHead>Permissions</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                            <TableBody>
                                {roles.map((role) => (
                                    <TableRow
                                        key={role.id}
                                        className={dataTableBodyRowClass()}
                                        onClick={() => router.visit(`/organization/roles/${role.id}`)}
                                    >
                                        <TableCell className={dataTableCellPrimaryClass()}>{role.name}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {role.permissions.length ? role.permissions.slice(0, 4).join(', ') : '—'}
                                            {role.permissions.length > 4 ? ` (+${role.permissions.length - 4} more)` : ''}
                                        </TableCell>
                                        <TableCell className={dataTableActionsCellClass()}>
                                            <ListTableCrudActions
                                                viewHref={`/organization/roles/${role.id}`}
                                                onEdit={(e) => {
                                                    e.stopPropagation();
                                                    handleEdit(role);
                                                }}
                                                onDelete={(e) => {
                                                    e.stopPropagation();
                                                    handleDelete(role);
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                </OrganizationDataTable>
            )}

            {roles.length === 0 ? <EmptyState title="No roles found." /> : null}

            <Pagination {...list.paginationProps} label="roles" />

            <RoleFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                role={currentRole}
                form={form}
                onSubmit={submit}
            />

            <RoleFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ has_permissions: '' })}
            />

            <RoleDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} role={currentRole} onConfirm={confirmDelete} />
        </Main>
    );
}
