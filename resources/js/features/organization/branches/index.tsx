import { router, useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { ListTableCrudActions } from '@/components/list-table-actions';
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
    filters: { country: string; status: string; city: string; headquartersOnly: boolean; hasEmail: boolean; hasPhone: boolean };
    countries: Country[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/branches',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference('branches:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentBranch, setCurrentBranch] = useState<Branch | null>(null);

    const filters: BranchFilters = {
        country: initialFilters.country,
        status: initialFilters.status,
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
        setCurrentBranch(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            code: '',
            address: '',
            city: '',
            country: countries.find((c) => c.code === 'UAE')?.code ?? countries[0]?.code ?? '',
            phone: '',
            email: '',
            is_headquarters: false,
            status: 'active',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (branch: Branch) => {
        setCurrentBranch(branch);
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
        setIsSheetOpen(true);
    };

    const handleDeleteClick = (branch: Branch) => {
        setCurrentBranch(branch);
        setIsDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!currentBranch) return;
        router.delete(`/organization/branches/${currentBranch.id}`, {
            onFinish: () => {
                setIsDeleteDialogOpen(false);
                setCurrentBranch(null);
            },
        });
    };

    const toggleStatus = (branch: Branch, enabled: boolean) => {
        router.put(
            `/organization/branches/${branch.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const handleFiltersChange = (next: BranchFilters) => {
        list.applyFilters(next);
    };

    const resetFilters = () => {
        handleFiltersChange({ country: '', status: '', city: '', headquartersOnly: false, hasEmail: false, hasPhone: false });
    };

    const submit = () => {
        if (currentBranch) {
            form.put(`/organization/branches/${currentBranch.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });
            return;
        }
        form.post('/organization/branches', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();
        if (initialSearch) params.set('search', initialSearch);
        if (initialFilters.country) params.set('country', initialFilters.country);
        if (initialFilters.status) params.set('status', initialFilters.status);
        if (initialFilters.city) params.set('city', initialFilters.city);
        if (initialFilters.headquartersOnly) params.set('headquartersOnly', '1');
        if (initialFilters.hasEmail) params.set('hasEmail', '1');
        if (initialFilters.hasPhone) params.set('hasPhone', '1');
        params.set('format', format);
        return `/organization/branches/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Branches"
                description="Manage branches across your companies."
                right={
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Branch
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search branches by name, code, company, or location..."
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

                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {branches.map((branch) => (
                        <BranchCard
                            key={branch.id}
                            branch={branch}
                            onEdit={handleEdit}
                            onDelete={handleDeleteClick}
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
                                    <DataTableHead className="text-right">Actions</DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {branches.map((branch) => (
                                    <TableRow
                                        key={branch.id}
                                        className={dataTableBodyRowClass()}
                                        onClick={() => router.visit(`/organization/branches/${branch.id}`)}
                                    >
                                        <TableCell className={dataTableCellPrimaryClass()}>{branch.name}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{branch.code ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{branch.is_headquarters ? 'Yes' : '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {[branch.city, branch.country].filter(Boolean).join(', ') || '—'}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>{branch.email ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{branch.phone ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <div className="flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
                                                <Switch
                                                    checked={branch.status === 'active'}
                                                    onCheckedChange={(checked) => toggleStatus(branch, checked)}
                                                />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {branch.status ?? '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className={dataTableActionsCellClass()}>
                                            <ListTableCrudActions
                                                viewHref={`/organization/branches/${branch.id}`}
                                                onEdit={(e) => {
                                                    e.stopPropagation();
                                                    handleEdit(branch);
                                                }}
                                                onDelete={(e) => {
                                                    e.stopPropagation();
                                                    handleDeleteClick(branch);
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                </OrganizationDataTable>
            )}

            {branches.length === 0 ? <EmptyState title="No branches found." /> : null}

            <Pagination {...list.paginationProps} label="branches" />

            <BranchFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                branch={currentBranch}
                countries={countries}
                form={form}
                onSubmit={submit}
            />

            <BranchDeleteDialog
                open={isDeleteDialogOpen}
                onOpenChange={setIsDeleteDialogOpen}
                branch={currentBranch}
                onConfirm={confirmDelete}
            />

            <BranchFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                countries={countries}
                value={filters}
                onChange={handleFiltersChange}
                onReset={resetFilters}
            />
        </Main>
    );
}
