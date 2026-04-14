import { useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { RoleCard } from './components/role-card';
import { RoleDeleteDialog } from './components/role-delete-dialog';
import { RoleFiltersSheet } from './components/role-filters-sheet';
import type { RoleFilters } from './components/role-filters-sheet';
import { RoleFormSheet } from './components/role-form-sheet';
import type { Company, Role, RoleFormData } from './types';

const emptyFilters: RoleFilters = {
    company_id: '',
    is_system: '',
};

export function RolesContent({
    roles,
    companies,
}: {
    roles: Role[];
    companies: Company[];
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentRole, setCurrentRole] = useState<Role | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<RoleFilters>(emptyFilters);

    const form = useForm<RoleFormData>({
        company_id: '',
        name: '',
        slug: '',
        permissions: [],
        is_system: false,
    });

    const handleAdd = () => {
        setCurrentRole(null);
        form.reset();
        form.clearErrors();
        form.setData({
            company_id: companies[0]?.id ?? '',
            name: '',
            slug: '',
            permissions: [],
            is_system: false,
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (role: Role) => {
        setCurrentRole(role);
        form.reset();
        form.clearErrors();
        form.setData({
            company_id: role.company.id ?? '',
            name: role.name ?? '',
            slug: role.slug ?? '',
            permissions: role.permissions ?? [],
            is_system: Boolean(role.is_system),
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (role: Role) => {
        setCurrentRole(role);
        setIsDeleteOpen(true);
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

    const filteredRoles = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return roles.filter((r) => {
            if (filters.company_id && String(r.company.id ?? '') !== filters.company_id) {
                return false;
            }

            if (filters.is_system) {
                const expected = filters.is_system === 'true';

                if (Boolean(r.is_system) !== expected) {
                    return false;
                }
            }

            if (!query) {
                return true;
            }

            return (
                r.name.toLowerCase().includes(query) ||
                r.slug.toLowerCase().includes(query) ||
                (r.company.name ?? '').toLowerCase().includes(query) ||
                (r.permissions ?? []).some((p) => p.toLowerCase().includes(query))
            );
        });
    }, [roles, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [filters.company_id, filters.is_system].filter(Boolean).length;
    }, [filters]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.company_id) {
            params.set('company_id', filters.company_id);
        }

        if (filters.is_system) {
            params.set('is_system', filters.is_system);
        }

        params.set('format', format);

        return `/organization/roles/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Roles & Permissions"
                description="Create roles and assign permissions per company."
                right={
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Role
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search roles by name, slug, company, or permission..."
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
                {filteredRoles.map((role) => (
                    <RoleCard key={role.id} role={role} onEdit={handleEdit} onDelete={handleDelete} />
                ))}
            </div>

            {filteredRoles.length === 0 ? <EmptyState title="No roles found." /> : null}

            <RoleFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                role={currentRole}
                companies={companies}
                form={form}
                onSubmit={submit}
            />

            <RoleFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                companies={companies}
                value={filters}
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
            />

            <RoleDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} role={currentRole} />
        </Main>
    );
}

