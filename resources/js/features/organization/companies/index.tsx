import { router, useForm } from '@inertiajs/react';
import {
    Edit2,
    Eye,
    Filter,
    Plus,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
import { CompanyCard } from './components/company-card';
import { CompanyDeleteDialog } from './components/company-delete-dialog';
import { CompanyFiltersSheet } from './components/company-filters-sheet';
import { CompanyFormSheet } from './components/company-form-sheet';
import type { Company, CompanyFormData, Country, Currency } from './types';

const emptyFilters = {
    industry: '',
    country: '',
    currency: '',
    hasLogo: false,
    hasEmail: false,
    hasWebsite: false,
};

export function CompaniesContent({
    companies,
    countries,
    currencies,
}: {
    companies: Company[];
    countries: Country[];
    currencies: Currency[];
}) {
    const [view, setView] = useViewPreference('companies:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentCompany, setCurrentCompany] = useState<Company | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState(emptyFilters);

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
                onSuccess: () => {
                    toast.success(`Company "${company.name}" is now ${enabled ? 'Active' : 'Inactive'}.`);
                },
                onError: () => {
                    toast.error('Failed to update status. Please try again.');
                },
            },
        );
    };

    const filteredCompanies = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();
        const industry = filters.industry.trim().toLowerCase();
        const country = filters.country.trim().toLowerCase();
        const currency = filters.currency.trim().toLowerCase();

        return companies.filter((c) => {
            const location = `${c.city ?? ''} ${c.country.code ?? ''}`.trim();
            const matchesSearch =
                !query ||
                c.name.toLowerCase().includes(query) ||
                (c.industry ?? '').toLowerCase().includes(query) ||
                location.toLowerCase().includes(query);

            if (!matchesSearch) {
                return false;
            }

            if (industry && !(c.industry ?? '').toLowerCase().includes(industry)) {
                return false;
            }

            if (
                country &&
                !(`${c.country.code ?? ''} ${c.country.name ?? ''}`.trim().toLowerCase().includes(country))
            ) {
                return false;
            }

            if (currency && (c.currency.code ?? '').toLowerCase() !== currency) {
                return false;
            }

            if (filters.hasLogo && !c.logo_url) {
                return false;
            }

            if (filters.hasEmail && !c.email) {
                return false;
            }

            if (filters.hasWebsite && !c.website) {
                return false;
            }

            return true;
        });
    }, [companies, searchQuery, filters]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.industry.trim()) {
            params.set('industry', filters.industry.trim());
        }

        if (filters.country.trim()) {
            params.set('country', filters.country.trim());
        }

        if (filters.currency.trim()) {
            params.set('currency', filters.currency.trim());
        }

        if (filters.hasLogo) {
            params.set('hasLogo', '1');
        }

        if (filters.hasEmail) {
            params.set('hasEmail', '1');
        }

        if (filters.hasWebsite) {
            params.set('hasWebsite', '1');
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
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Company
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search companies by name, industry, or location..."
                value={searchQuery}
                onChange={setSearchQuery}
                right={
                    <>
                        <ViewToggle value={view} onChange={setView} />

                        <Button
                            variant="outline"
                            className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 py-6 px-6"
                            onClick={() => setIsFiltersOpen(true)}
                        >
                            <Filter className="mr-2 h-4 w-4" />
                            Filters
                        </Button>

                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="outline"
                            buttonClassName="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 py-6 px-6"
                        />
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {filteredCompanies.map((company) => (
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
                <Card className="border-white/5 bg-white/5 backdrop-blur-xl overflow-hidden">
                    <CardContent className="p-0">
                        <Table className="min-w-[860px]">
                            <TableHeader>
                                <TableRow className="border-white/10">
                                    <TableHead className="pl-4">Company</TableHead>
                                    <TableHead>Industry</TableHead>
                                    <TableHead>Location</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right pr-4">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredCompanies.map((company) => (
                                    <TableRow
                                        key={company.id}
                                        className="border-white/5 cursor-pointer hover:bg-white/5"
                                        onClick={() => router.visit(`/organization/companies/${company.id}`)}
                                    >
                                        <TableCell className="pl-4 font-semibold">
                                            {company.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            {company.industry ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            {[company.city, company.country.name].filter(Boolean).join(', ') || '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            {company.currency.code ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            <div
                                                className="flex items-center gap-3"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                <Switch
                                                    checked={company.status === 'active'}
                                                    onCheckedChange={(checked) => toggleStatus(company, checked)}
                                                />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {company.status ?? '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="pr-4">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-white/10"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        router.visit(`/organization/companies/${company.id}`);
                                                    }}
                                                    title="View"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-white/10"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleEdit(company);
                                                    }}
                                                    title="Edit"
                                                >
                                                    <Edit2 className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-destructive/10 text-destructive hover:text-destructive"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleDeleteClick(company);
                                                    }}
                                                    title="Delete"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}

            {filteredCompanies.length === 0 ? (
                <EmptyState title="No companies found." />
            ) : null}

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
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
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
