import { router, useForm } from '@inertiajs/react';
import { Edit2, Eye, Filter, Plus, Trash2 } from 'lucide-react';
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
import { DepartmentCard } from './components/department-card';
import { DepartmentDeleteDialog } from './components/department-delete-dialog';
import { DepartmentFiltersSheet } from './components/department-filters-sheet';
import type { DepartmentFilters } from './components/department-filters-sheet';
import { DepartmentFormSheet } from './components/department-form-sheet';
import type { Branch, Department, DepartmentFormData, DepartmentParentOption, Manager } from './types';

const emptyFilters: DepartmentFilters = {
    branch_id: '',
    parent_id: '',
    manager_id: '',
    status: '',
    code: '',
};

export function DepartmentsContent({
    departments,
    branches,
    parents,
    managers,
}: {
    departments: Department[];
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    const [view, setView] = useViewPreference('departments:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentDepartment, setCurrentDepartment] = useState<Department | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<DepartmentFilters>(emptyFilters);

    const form = useForm<DepartmentFormData>({
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

    const confirmDelete = () => {
        if (!currentDepartment) {
            return;
        }

        router.delete(`/organization/departments/${currentDepartment.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentDepartment(null);
            },
        });
    };

    const toggleStatus = (department: Department, enabled: boolean) => {
        router.put(
            `/organization/departments/${department.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Department "${department.name}" is now ${enabled ? 'Active' : 'Inactive'}.`);
                },
                onError: () => {
                    toast.error('Failed to update status. Please try again.');
                },
            },
        );
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
                (d.branch?.name ?? '').toLowerCase().includes(query) ||
                (d.parent?.name ?? '').toLowerCase().includes(query) ||
                (d.manager?.name ?? '').toLowerCase().includes(query)
            );
        });
    }, [departments, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [
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
                        <ViewToggle value={view} onChange={setView} />
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

            {view === 'grid' ? (
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {filteredDepartments.map((department) => (
                        <DepartmentCard
                            key={department.id}
                            department={department}
                            onEdit={handleEdit}
                            onDelete={handleDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <Card className="border-white/5 bg-white/5 backdrop-blur-xl overflow-hidden">
                    <CardContent className="p-0">
                        <Table className="min-w-[980px]">
                            <TableHeader>
                                <TableRow className="border-white/10">
                                    <TableHead className="pl-4">Department</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Branch</TableHead>
                                    <TableHead>Parent</TableHead>
                                    <TableHead>Manager</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right pr-4">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredDepartments.map((department) => (
                                    <TableRow
                                        key={department.id}
                                        className="border-white/5 cursor-pointer hover:bg-white/5"
                                        onClick={() => router.visit(`/organization/departments/${department.id}`)}
                                    >
                                        <TableCell className="pl-4 font-semibold">{department.name}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{department.code ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{department.branch?.name ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{department.parent?.name ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{department.manager?.name ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            <div className="flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
                                                <Switch
                                                    checked={department.status === 'active'}
                                                    onCheckedChange={(checked) => toggleStatus(department, checked)}
                                                />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {department.status ?? '—'}
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
                                                        router.visit(`/organization/departments/${department.id}`);
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
                                                        handleEdit(department);
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
                                                        handleDelete(department);
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

            {filteredDepartments.length === 0 ? <EmptyState title="No departments found." /> : null}

            <DepartmentFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                department={currentDepartment}
                branches={branches}
                parents={parents}
                managers={managers}
                form={form}
                onSubmit={submit}
            />

            <DepartmentFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                branches={branches}
                parents={parents}
                managers={managers}
                value={filters}
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
            />

            <DepartmentDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                department={currentDepartment}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}

