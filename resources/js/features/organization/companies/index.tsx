import { router, useForm } from '@inertiajs/react';
import {
    Download,
    Filter,
    Plus,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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

    const exportCompanies = (format: 'csv' | 'xlsx' | 'pdf') => {
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

        window.location.href = `/organization/companies/export?${params.toString()}`;
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
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2 mb-1">
                        <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                            Organization Management
                        </span>
                    </div>
                    <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                        Companies
                    </h1>
                    <p className="text-sm text-muted-foreground/80 font-medium">
                        Manage your multi-company structure and general information.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Company
                    </Button>
                </div>
            </div>

            <div className="flex items-center gap-4 mb-8">
                <div className="relative flex-1 group">
                    <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground transition-colors group-focus-within:text-foreground" />
                    <Input 
                        placeholder="Search companies by name, industry, or location..." 
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="pl-10 rounded-xl border-white/5 bg-white/5 focus-visible:ring-primary/20 focus-visible:bg-white/10 transition-all py-6 text-base"
                    />
                </div>
                <Button
                    variant="outline"
                    className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 py-6 px-6"
                    onClick={() => setIsFiltersOpen(true)}
                >
                    <Filter className="mr-2 h-4 w-4" />
                    Filters
                </Button>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 py-6 px-6">
                            <Download className="mr-2 h-4 w-4" />
                            Export
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-44">
                        <DropdownMenuItem onClick={() => exportCompanies('csv')}>CSV</DropdownMenuItem>
                        <DropdownMenuItem onClick={() => exportCompanies('xlsx')}>Excel</DropdownMenuItem>
                        <DropdownMenuItem onClick={() => exportCompanies('pdf')}>PDF</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {filteredCompanies.map((company) => (
                    <CompanyCard
                        key={company.id}
                        company={company}
                        onEdit={handleEdit}
                        onDelete={handleDeleteClick}
                    />
                ))}
            </div>

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
