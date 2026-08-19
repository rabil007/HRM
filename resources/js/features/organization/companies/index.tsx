import { router, useForm } from '@inertiajs/react';
import { FolderOpen, Plus } from 'lucide-react';
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
import { index as companyDocumentsIndex } from '@/routes/organization/companies/documents';
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
    const crud = useOrganizationCrudList<Company>({
        viewKey: 'companies:view',
    });

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
        remove_logo: false,
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
        wps_employer_iban: '',
        status: 'active',
    });

    const handleAdd = () => {
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({
                logo: null,
                remove_logo: false,
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
                wps_employer_iban: '',
                status: 'active',
            });
        });
    };

    const handleEdit = (company: Company) => {
        crud.openEdit(company, () => {
            form.reset();
            form.clearErrors();

            const payrollCycle =
                company.payroll_cycle === 'monthly' ||
                company.payroll_cycle === 'biweekly' ||
                company.payroll_cycle === 'weekly'
                    ? company.payroll_cycle
                    : 'monthly';

            form.setData({
                logo: null,
                remove_logo: false,
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
                wps_employer_iban: company.wps_employer_iban ?? '',
                status: company.status ?? 'active',
            });
        });
    };

    const confirmDelete = () => {
        if (!crud.currentEntity) {
            return;
        }

        router.delete(`/organization/companies/${crud.currentEntity.id}`, {
            onFinish: () => crud.confirmDeleteFinish(),
        });
    };

    const toggleStatus = (company: Company, enabled: boolean) => {
        router.put(
            `/organization/companies/${company.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const handleFiltersChange = (next: CompanyFilters) => {
        list.applyFilters({
            industry: next.industry,
            country: next.country,
            currency: next.currency,
        });
    };

    const resetFilters = () => {
        handleFiltersChange({
            industry: '',
            country: '',
            currency: '',
            hasLogo: false,
            hasEmail: false,
            hasWebsite: false,
        });
    };

    const submit = () => {
        if (crud.currentEntity) {
            form.put(`/organization/companies/${crud.currentEntity.id}`, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/companies', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') =>
        buildListExportUrl('/organization/companies/export', {
            search: initialSearch,
            industry: initialFilters.industry,
            country: initialFilters.country,
            currency: initialFilters.currency,
            format,
        });

    return (
        <OrganizationListPageShell
            title="Companies"
            description="Manage your multi-company structure and general information."
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
                        Add Company
                    </Button>
                </>
            }
            search={{
                placeholder:
                    'Search companies by name, industry, or location...',
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
                <Pagination {...list.paginationProps} label="companies" />
            }
        >
            {crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {companies.map((company) => (
                        <CompanyCard
                            key={company.id}
                            company={company}
                            onEdit={handleEdit}
                            onDelete={crud.openDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[860px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">
                                Company
                            </DataTableHead>
                            <DataTableHead>Industry</DataTableHead>
                            <DataTableHead>Location</DataTableHead>
                            <DataTableHead>Currency</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {companies.map((company) => (
                            <TableRow
                                key={company.id}
                                className={dataTableBodyRowClass()}
                                onClick={() =>
                                    router.visit(
                                        `/organization/companies/${company.id}`,
                                    )
                                }
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div className="flex items-center gap-3">
                                        <div className={`flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border text-foreground/80 ${company.logo_url ? 'border-primary/20 bg-primary/5' : 'border-border/60 bg-muted/40 dark:border-white/10 dark:bg-white/6'}`}>
                                            {company.logo_url ? (
                                                <img
                                                    src={company.logo_url}
                                                    alt={company.name}
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <span className="text-[10px] font-extrabold tracking-tight">
                                                    {company.name
                                                        .split(' ')
                                                        .filter(Boolean)
                                                        .slice(0, 2)
                                                        .map((p) => p[0]?.toUpperCase())
                                                        .join('')}
                                                </span>
                                            )}
                                        </div>
                                        {company.name}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {company.industry ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {[company.city, company.country.name]
                                        .filter(Boolean)
                                        .join(', ') || '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {company.currency.code ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div
                                        className="flex items-center gap-3"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Switch
                                            checked={
                                                company.status === 'active'
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleStatus(company, checked)
                                            }
                                        />
                                        <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            {company.status ?? '—'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <div className="flex items-center justify-end gap-1">
                                        {company.can_view_documents ? (
                                            <Button
                                                asChild
                                                variant="ghost"
                                                size="icon"
                                                title="Company documents"
                                            >
                                                <a
                                                    href={companyDocumentsIndex.url(
                                                        company.id,
                                                    )}
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    <FolderOpen className="h-4 w-4" />
                                                </a>
                                            </Button>
                                        ) : null}
                                        <ListTableCrudActions
                                            viewHref={`/organization/companies/${company.id}`}
                                            onEdit={(e) => {
                                                e.stopPropagation();
                                                handleEdit(company);
                                            }}
                                            onDelete={(e) => {
                                                e.stopPropagation();
                                                crud.openDelete(company);
                                            }}
                                        />
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {companies.length === 0 ? (
                <EmptyState title="No companies found." />
            ) : null}

            <CompanyFormSheet
                open={crud.isSheetOpen}
                onOpenChange={crud.setIsSheetOpen}
                company={crud.currentEntity}
                countries={countries}
                currencies={currencies}
                form={form}
                onSubmit={submit}
            />

            <CompanyFiltersSheet
                open={crud.isFiltersOpen}
                onOpenChange={crud.setIsFiltersOpen}
                countries={countries}
                currencies={currencies}
                value={filters}
                onChange={handleFiltersChange}
                onReset={resetFilters}
            />

            <CompanyDeleteDialog
                open={crud.isDeleteDialogOpen}
                onOpenChange={crud.setIsDeleteDialogOpen}
                company={crud.currentEntity}
                onConfirm={confirmDelete}
            />
        </OrganizationListPageShell>
    );
}
