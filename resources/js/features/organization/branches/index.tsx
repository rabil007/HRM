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
import { BranchCard } from './components/branch-card';
import { BranchDeleteDialog } from './components/branch-delete-dialog';
import { BranchFiltersSheet } from './components/branch-filters-sheet';
import type { BranchFilters } from './components/branch-filters-sheet';
import { BranchFormSheet } from './components/branch-form-sheet';
import type { Branch, BranchFormData, Country } from './types';

export function BranchesContent({
    branches,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    countries,
}: {
    branches: Branch[];
    pagination: PaginationMeta;
    search: string;
    filters: {
        country: string;
        status: string;
        city: string;
        headquartersOnly: boolean;
        hasEmail: boolean;
        hasPhone: boolean;
    };
    countries: Country[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/branches',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const crud = useOrganizationCrudList<Branch>({ viewKey: 'branches:view' });

    const filters: BranchFilters = {
        country: initialFilters.country,
        status: initialFilters.status as BranchFilters['status'],
        city: initialFilters.city,
        headquartersOnly: initialFilters.headquartersOnly,
        hasEmail: initialFilters.hasEmail,
        hasPhone: initialFilters.hasPhone,
    };

    const activeFiltersCount = [
        initialFilters.country,
        initialFilters.status,
        initialFilters.city,
        initialFilters.headquartersOnly ? '1' : '',
        initialFilters.hasEmail ? '1' : '',
        initialFilters.hasPhone ? '1' : '',
    ].filter(Boolean).length;

    const form = useForm<BranchFormData>({
        name: '',
        code: '',
        address: '',
        city: '',
        country: '',
        phone: '',
        email: '',
        is_headquarters: false,
        status: 'active',
    });

    const handleAdd = () => {
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({
                name: '',
                code: '',
                address: '',
                city: '',
                country:
                    countries.find((c) => c.code === 'UAE')?.code ??
                    countries[0]?.code ??
                    '',
                phone: '',
                email: '',
                is_headquarters: false,
                status: 'active',
            });
        });
    };

    const handleEdit = (branch: Branch) => {
        crud.openEdit(branch, () => {
            form.reset();
            form.clearErrors();
            form.setData({
                name: branch.name ?? '',
                code: branch.code ?? '',
                address: branch.address ?? '',
                city: branch.city ?? '',
                country: branch.country ?? '',
                phone: branch.phone ?? '',
                email: branch.email ?? '',
                is_headquarters: branch.is_headquarters ?? false,
                status: branch.status ?? 'active',
            });
        });
    };

    const confirmDelete = () => {
        if (!crud.currentEntity) {
            return;
        }

        router.delete(`/organization/branches/${crud.currentEntity.id}`, {
            onFinish: () => crud.confirmDeleteFinish(),
        });
    };

    const toggleStatus = (branch: Branch, enabled: boolean) => {
        router.put(
            `/organization/branches/${branch.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const handleFiltersChange = (next: BranchFilters) => {
        list.applyFilters(next);
    };

    const resetFilters = () => {
        handleFiltersChange({
            country: '',
            status: '',
            city: '',
            headquartersOnly: false,
            hasEmail: false,
            hasPhone: false,
        });
    };

    const submit = () => {
        if (crud.currentEntity) {
            form.put(`/organization/branches/${crud.currentEntity.id}`, {
                preserveScroll: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/branches', {
            preserveScroll: true,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') =>
        buildListExportUrl('/organization/branches/export', {
            search: initialSearch,
            country: initialFilters.country,
            status: initialFilters.status,
            city: initialFilters.city,
            headquartersOnly: initialFilters.headquartersOnly,
            hasEmail: initialFilters.hasEmail,
            hasPhone: initialFilters.hasPhone,
            format,
        });

    return (
        <OrganizationListPageShell
            title="Branches"
            description="Manage branches across your companies."
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
                        Add Branch
                    </Button>
                </>
            }
            search={{
                placeholder:
                    'Search branches by name, code, company, or location...',
                value: list.searchInput,
                onChange: list.onSearchChange,
                right: crud.view && crud.setView ? (
                    <ViewToggle value={crud.view} onChange={crud.setView} />
                ) : null,
            }}
            filtersButton={{
                onClick: () => crud.setIsFiltersOpen(true),
                activeFiltersCount,
            }}
            pagination={
                <Pagination {...list.paginationProps} label="branches" />
            }
        >
            {crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {branches.map((branch) => (
                        <BranchCard
                            key={branch.id}
                            branch={branch}
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
                            <DataTableHead className="pl-5">Branch</DataTableHead>
                            <DataTableHead>Code</DataTableHead>
                            <DataTableHead>HQ</DataTableHead>
                            <DataTableHead>Location</DataTableHead>
                            <DataTableHead>Email</DataTableHead>
                            <DataTableHead>Phone</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {branches.map((branch) => (
                            <TableRow
                                key={branch.id}
                                className={dataTableBodyRowClass()}
                                onClick={() =>
                                    router.visit(
                                        `/organization/branches/${branch.id}`,
                                    )
                                }
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    {branch.name}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {branch.code ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {branch.is_headquarters ? 'Yes' : '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {[branch.city, branch.country]
                                        .filter(Boolean)
                                        .join(', ') || '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {branch.email ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {branch.phone ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div
                                        className="flex items-center gap-3"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Switch
                                            checked={branch.status === 'active'}
                                            onCheckedChange={(checked) =>
                                                toggleStatus(branch, checked)
                                            }
                                        />
                                        <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            {branch.status ?? '—'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <ListTableCrudActions
                                        viewHref={`/organization/branches/${branch.id}`}
                                        onEdit={(e) => {
                                            e.stopPropagation();
                                            handleEdit(branch);
                                        }}
                                        onDelete={(e) => {
                                            e.stopPropagation();
                                            crud.openDelete(branch);
                                        }}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {branches.length === 0 ? (
                <EmptyState title="No branches found." />
            ) : null}

            <BranchFormSheet
                open={crud.isSheetOpen}
                onOpenChange={crud.setIsSheetOpen}
                branch={crud.currentEntity}
                countries={countries}
                form={form}
                onSubmit={submit}
            />

            <BranchDeleteDialog
                open={crud.isDeleteDialogOpen}
                onOpenChange={crud.setIsDeleteDialogOpen}
                branch={crud.currentEntity}
                onConfirm={confirmDelete}
            />

            <BranchFiltersSheet
                open={crud.isFiltersOpen}
                onOpenChange={crud.setIsFiltersOpen}
                countries={countries}
                value={filters}
                onChange={handleFiltersChange}
                onReset={resetFilters}
            />
        </OrganizationListPageShell>
    );
}
