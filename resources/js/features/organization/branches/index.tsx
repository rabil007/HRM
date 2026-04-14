import { router, useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { BranchCard } from './components/branch-card';
import { BranchDeleteDialog } from './components/branch-delete-dialog';
import { BranchFiltersSheet } from './components/branch-filters-sheet';
import type { BranchFilters } from './components/branch-filters-sheet';
import { BranchFormSheet } from './components/branch-form-sheet';
import type { Branch, BranchFormData, Country } from './types';

export function BranchesContent({
    branches,
    countries,
}: {
    branches: Branch[];
    countries: Country[];
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentBranch, setCurrentBranch] = useState<Branch | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<BranchFilters>({
        country: '',
        status: '',
        headquartersOnly: false,
        hasEmail: false,
        hasPhone: false,
        city: '',
    });

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
        if (!currentBranch) {
            return;
        }

        router.delete(`/organization/branches/${currentBranch.id}`, {
            onFinish: () => {
                setIsDeleteDialogOpen(false);
                setCurrentBranch(null);
            },
        });
    };

    const filteredBranches = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return branches
            .filter((b) => {
                if (filters.country && (b.country ?? '') !== filters.country) {
                    return false;
                }

                if (filters.status && (b.status ?? '') !== filters.status) {
                    return false;
                }

                if (filters.headquartersOnly && b.is_headquarters !== true) {
                    return false;
                }

                if (filters.hasEmail && !(b.email ?? '').trim()) {
                    return false;
                }

                if (filters.hasPhone && !(b.phone ?? '').trim()) {
                    return false;
                }

                if (filters.city && !(b.city ?? '').toLowerCase().includes(filters.city.trim().toLowerCase())) {
                    return false;
                }

                return true;
            })
            .filter((b) => {
                if (!query) {
                    return true;
                }

                return (
                    b.name.toLowerCase().includes(query) ||
                    (b.code ?? '').toLowerCase().includes(query) ||
                    (`${b.city ?? ''} ${b.country ?? ''}`.trim().toLowerCase().includes(query)) ||
                    (b.email ?? '').toLowerCase().includes(query)
                );
            });
    }, [branches, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [
            filters.country,
            filters.status,
            filters.city.trim(),
            filters.headquartersOnly ? '1' : '',
            filters.hasEmail ? '1' : '',
            filters.hasPhone ? '1' : '',
        ].filter(Boolean).length;
    }, [filters]);

    const resetFilters = () => {
        setFilters({
            country: '',
            status: '',
            headquartersOnly: false,
            hasEmail: false,
            hasPhone: false,
            city: '',
        });
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.country) {
            params.set('country', filters.country);
        }

        if (filters.status) {
            params.set('status', filters.status);
        }

        if (filters.city.trim()) {
            params.set('city', filters.city.trim());
        }

        if (filters.headquartersOnly) {
            params.set('headquartersOnly', '1');
        }

        if (filters.hasEmail) {
            params.set('hasEmail', '1');
        }

        if (filters.hasPhone) {
            params.set('hasPhone', '1');
        }

        params.set('format', format);

        return `/organization/branches/export?${params.toString()}`;
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
                value={searchQuery}
                onChange={setSearchQuery}
                right={
                    <>
                        <Button
                            type="button"
                            variant="secondary"
                            className="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
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
                            buttonClassName="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
                        />
                    </>
                }
            />

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {filteredBranches.map((branch) => (
                    <BranchCard
                        key={branch.id}
                        branch={branch}
                        onEdit={handleEdit}
                        onDelete={handleDeleteClick}
                    />
                ))}
            </div>

            {filteredBranches.length === 0 ? (
                <EmptyState title="No branches found." />
            ) : null}

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
                companies={companies}
                countries={countries}
                value={filters}
                onChange={setFilters}
                onReset={resetFilters}
            />
        </Main>
    );
}

