import { useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { DepartmentCard } from './components/department-card';
import { DepartmentDeleteDialog } from './components/department-delete-dialog';
import { DepartmentFiltersSheet  } from './components/department-filters-sheet';
import type {DepartmentFilters} from './components/department-filters-sheet';
import { DepartmentFormSheet } from './components/department-form-sheet';
import type { Branch, Company, Department, DepartmentFormData, DepartmentParentOption, Manager } from './types';

const emptyFilters: DepartmentFilters = {
    company_id: '',
    branch_id: '',
    parent_id: '',
    manager_id: '',
    status: '',
    code: '',
};

export function DepartmentsContent({
    departments,
    companies,
    branches,
    parents,
    managers,
}: {
    departments: Department[];
    companies: Company[];
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentDepartment, setCurrentDepartment] = useState<Department | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<DepartmentFilters>(emptyFilters);

    const form = useForm<DepartmentFormData>({
        company_id: '',
        branch_id: '',
        parent_id: '',
        manager_id: '',
        name: '',
        code: '',
        status: 'active',
    });

    const handleAdd = () => {
        setCurrentDepartment(null);
        form.reset();
        form.clearErrors();
        form.setData({
            company_id: companies[0]?.id ?? '',
            branch_id: '',
            parent_id: '',
            manager_id: '',
            name: '',
            code: '',
            status: 'active',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (department: Department) => {
        setCurrentDepartment(department);
        form.reset();
        form.clearErrors();
        form.setData({
            company_id: department.company.id ?? '',
            branch_id: department.branch?.id ?? '',
            parent_id: department.parent?.id ?? '',
            manager_id: department.manager?.id ?? '',
            name: department.name ?? '',
            code: department.code ?? '',
            status: department.status ?? 'active',
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (department: Department) => {
        setCurrentDepartment(department);
        setIsDeleteOpen(true);
    };

    const submit = () => {
        if (currentDepartment) {
            form.put(`/organization/departments/${currentDepartment.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/departments', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const filteredDepartments = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return departments.filter((d) => {
            if (filters.company_id && String(d.company.id ?? '') !== filters.company_id) {
                return false;
            }

            if (filters.branch_id && String(d.branch?.id ?? '') !== filters.branch_id) {
                return false;
            }

            if (filters.parent_id && String(d.parent?.id ?? '') !== filters.parent_id) {
                return false;
            }

            if (filters.manager_id && String(d.manager?.id ?? '') !== filters.manager_id) {
                return false;
            }

            if (filters.status && (d.status ?? '') !== filters.status) {
                return false;
            }

            if (filters.code.trim() && !(d.code ?? '').toLowerCase().includes(filters.code.trim().toLowerCase())) {
                return false;
            }

            if (!query) {
                return true;
            }

            return (
                d.name.toLowerCase().includes(query) ||
                (d.code ?? '').toLowerCase().includes(query) ||
                (d.company.name ?? '').toLowerCase().includes(query) ||
                (d.branch?.name ?? '').toLowerCase().includes(query) ||
                (d.parent?.name ?? '').toLowerCase().includes(query) ||
                (d.manager?.name ?? '').toLowerCase().includes(query)
            );
        });
    }, [departments, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [
            filters.company_id,
            filters.branch_id,
            filters.parent_id,
            filters.manager_id,
            filters.status,
            filters.code.trim(),
        ].filter(Boolean).length;
    }, [filters]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.company_id) {
            params.set('company_id', filters.company_id);
        }

        if (filters.branch_id) {
            params.set('branch_id', filters.branch_id);
        }

        if (filters.parent_id) {
            params.set('parent_id', filters.parent_id);
        }

        if (filters.manager_id) {
            params.set('manager_id', filters.manager_id);
        }

        if (filters.status) {
            params.set('status', filters.status);
        }

        if (filters.code.trim()) {
            params.set('code', filters.code.trim());
        }

        params.set('format', format);

        return `/organization/departments/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Departments"
                description="Manage departments across your organization."
                right={
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Department
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search departments by name, code, company, branch, or manager..."
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
                {filteredDepartments.map((department) => (
                    <DepartmentCard
                        key={department.id}
                        department={department}
                        onEdit={handleEdit}
                        onDelete={handleDelete}
                    />
                ))}
            </div>

            {filteredDepartments.length === 0 ? <EmptyState title="No departments found." /> : null}

            <DepartmentFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                department={currentDepartment}
                companies={companies}
                branches={branches}
                parents={parents}
                managers={managers}
                form={form}
                onSubmit={submit}
            />

            <DepartmentFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                companies={companies}
                branches={branches}
                parents={parents}
                managers={managers}
                value={filters}
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
            />

            <DepartmentDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} department={currentDepartment} />
        </Main>
    );
}

