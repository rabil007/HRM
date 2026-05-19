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
import { CompanyCard } from './components/company-card';
import { CompanyDeleteDialog } from './components/company-delete-dialog';
import { CompanyFiltersSheet } from './components/company-filters-sheet';
import type { CompanyFilters } from './components/company-filters-sheet';
import { CompanyFormSheet } from './components/company-form-sheet';
import type { Company, CompanyFormData, Country, Currency } from './types';

export function CompaniesContent({
    companies,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    countries,
    currencies,
}: {
    companies: Company[];
    pagination: PaginationMeta;
    search: string;
    filters: { industry: string; country: string; currency: string };
    countries: Country[];
    currencies: Currency[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/companies',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference('companies:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentCompany, setCurrentCompany] = useState<Company | null>(null);

    const filters: CompanyFilters = {
        industry: initialFilters.industry,
        country: initialFilters.country,
        currency: initialFilters.currency,
        hasLogo: false,
        hasEmail: false,
        hasWebsite: false,
    };

    const activeFiltersCount = [
        initialFilters.industry,
        initialFilters.country,
        initialFilters.currency,
    ].filter(Boolean).length;

    const form = useForm<CompanyFormData>({
        logo: null as File | null,
        name: '',
        industry: '',
        company_size: '',
        registration_number: '',
        tax_id: '',
        city: '',
        address: '',
        phone: '',
        country_id: '',
        email: '',
        website: '',
        currency_id: '',
        timezone: 'Asia/Dubai',
        payroll_cycle: 'monthly',
        working_days: [1, 2, 3, 4, 5],
        wps_agent_code: '',
        wps_mol_uid: '',
        status: 'active',
    });

    const handleAdd = () => {
        setCurrentCompany(null);
        form.reset();
        form.clearErrors();
        form.setData({
            logo: null,
            name: '',
            industry: '',
            company_size: '',
            registration_number: '',
            tax_id: '',
            city: '',
            address: '',
            phone: '',
            country_id: countries.find((c) => c.code === 'UAE')?.id ?? '',
            email: '',
            website: '',
            currency_id: currencies.find((c) => c.code === 'AED')?.id ?? '',
            timezone: 'Asia/Dubai',
            payroll_cycle: 'monthly',
            working_days: [1, 2, 3, 4, 5],
            wps_agent_code: '',
            wps_mol_uid: '',
            status: 'active',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (company: Company) => {
        setCurrentCompany(company);
        form.reset();
        form.clearErrors();

        const payrollCycle =
            company.payroll_cycle === 'monthly' || company.payroll_cycle === 'biweekly' || company.payroll_cycle === 'weekly'
                ? company.payroll_cycle
                : 'monthly';

        form.setData({
            logo: null,
            name: company.name ?? '',
            industry: company.industry ?? '',
            company_size: company.company_size ?? '',
            registration_number: company.registration_number ?? '',
            tax_id: company.tax_id ?? '',
            city: company.city ?? '',
            address: company.address ?? '',
            phone: company.phone ?? '',
            country_id: company.country.id ?? '',
            email: company.email ?? '',
            website: company.website ?? '',
            currency_id: company.currency.id ?? '',
            timezone: company.timezone ?? 'Asia/Dubai',
            payroll_cycle: payrollCycle,
            working_days: company.working_days ?? [1, 2, 3, 4, 5],
            wps_agent_code: company.wps_agent_code ?? '',
            wps_mol_uid: company.wps_mol_uid ?? '',
            status: company.status ?? 'active',
        });
        setIsSheetOpen(true);
    };

    const handleDeleteClick = (company: Company) => {
        setCurrentCompany(company);
        setIsDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!currentCompany) {
return;
}

        router.delete(`/organization/companies/${currentCompany.id}`, {
            onFinish: () => {
                setIsDeleteDialogOpen(false);
                setCurrentCompany(null);
            },
        });
    };

    const toggleStatus = (company: Company, enabled: boolean) => {
        router.put(
            `/organization/companies/${company.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const handleFiltersChange = (next: CompanyFilters) => {
        list.applyFilters({ industry: next.industry, country: next.country, currency: next.currency });
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (initialSearch) {
params.set('search', initialSearch);
}

        if (initialFilters.industry) {
params.set('industry', initialFilters.industry);
}

        if (initialFilters.country) {
params.set('country', initialFilters.country);
}

        if (initialFilters.currency) {
params.set('currency', initialFilters.currency);
}

        params.set('format', format);

        return `/organization/companies/export?${params.toString()}`;
    };

    const submit = () => {
        if (currentCompany) {
            form.put(`/organization/companies/${currentCompany.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/companies', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Companies"
                description="Manage your multi-company structure and general information."
                right={
                    <>
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                        <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                            <Plus className="mr-2 h-4 w-4" />
                            Add Company
                        </Button>
                    </>
                }
            />

            <SearchBar
                placeholder="Search companies by name, industry, or location..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={
                    <>
                        <ViewToggle value={view} onChange={setView} />

                        <Button
                            variant="outline"
                            className="glass-card rounded-xl py-6 px-6 hover:bg-accent"
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
                    {companies.map((company) => (
                        <CompanyCard
                            key={company.id}
                            company={company}
                            onEdit={handleEdit}
                            onDelete={handleDeleteClick}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[860px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Company</DataTableHead>
                            <DataTableHead>Industry</DataTableHead>
                            <DataTableHead>Location</DataTableHead>
                            <DataTableHead>Currency</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                            <TableBody>
                                {companies.map((company) => (
                                    <TableRow
                                        key={company.id}
                                        className={dataTableBodyRowClass()}
                                        onClick={() => router.visit(`/organization/companies/${company.id}`)}
                                    >
                                        <TableCell className={dataTableCellPrimaryClass()}>{company.name}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{company.industry ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {[company.city, company.country.name].filter(Boolean).join(', ') || '—'}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>{company.currency.code ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <div className="flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
                                                <Switch
                                                    checked={company.status === 'active'}
                                                    onCheckedChange={(checked) => toggleStatus(company, checked)}
                                                />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {company.status ?? '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className={dataTableActionsCellClass()}>
                                            <ListTableCrudActions
                                                viewHref={`/organization/companies/${company.id}`}
                                                onEdit={(e) => {
                                                    e.stopPropagation();
                                                    handleEdit(company);
                                                }}
                                                onDelete={(e) => {
                                                    e.stopPropagation();
                                                    handleDeleteClick(company);
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                </OrganizationDataTable>
            )}

            {companies.length === 0 ? <EmptyState title="No companies found." /> : null}

            <Pagination {...list.paginationProps} label="companies" />

            <CompanyFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                company={currentCompany}
                countries={countries}
                currencies={currencies}
                form={form}
                onSubmit={submit}
            />

            <CompanyFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                countries={countries}
                currencies={currencies}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ industry: '', country: '', currency: '', hasLogo: false, hasEmail: false, hasWebsite: false })}
            />

            <CompanyDeleteDialog
                open={isDeleteDialogOpen}
                onOpenChange={setIsDeleteDialogOpen}
                company={currentCompany}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
