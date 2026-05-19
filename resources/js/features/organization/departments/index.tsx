import { router, useForm } from '@inertiajs/react';
import { Edit2, Eye, Filter, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { DepartmentCard } from './components/department-card';
import { DepartmentDeleteDialog } from './components/department-delete-dialog';
import { DepartmentFiltersSheet } from './components/department-filters-sheet';
import type { DepartmentFilters } from './components/department-filters-sheet';
import { DepartmentFormSheet } from './components/department-form-sheet';
import type { Branch, Department, DepartmentFormData, DepartmentParentOption, Manager } from './types';

export function DepartmentsContent({
    departments,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    branches,
    parents,
    managers,
}: {
    departments: Department[];
    pagination: PaginationMeta;
    search: string;
    filters: { branch_id: string; parent_id: string; manager_id: string; status: string; code: string };
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/departments',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference('departments:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentDepartment, setCurrentDepartment] = useState<Department | null>(null);

    const filters: DepartmentFilters = {
        branch_id: initialFilters.branch_id,
        parent_id: initialFilters.parent_id,
        manager_id: initialFilters.manager_id,
        status: initialFilters.status,
        code: initialFilters.code,
    };

    const activeFiltersCount = [
        initialFilters.branch_id,
        initialFilters.parent_id,
        initialFilters.manager_id,
        initialFilters.status,
        initialFilters.code.trim(),
    ].filter(Boolean).length;

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
        form.setData({ branch_id: '', parent_id: '', manager_id: '', name: '', code: '', status: 'active' });
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
        if (!currentDepartment) return;
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
                onError: () => toast.error('Failed to update status. Please try again.'),
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

    const handleFiltersChange = (next: DepartmentFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();
        if (initialSearch) params.set('search', initialSearch);
        if (initialFilters.branch_id) params.set('branch_id', initialFilters.branch_id);
        if (initialFilters.parent_id) params.set('parent_id', initialFilters.parent_id);
        if (initialFilters.manager_id) params.set('manager_id', initialFilters.manager_id);
        if (initialFilters.status) params.set('status', initialFilters.status);
        if (initialFilters.code) params.set('code', initialFilters.code);
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
                    {departments.map((department) => (
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
                <Card className="glass-card w-full overflow-hidden">
                    <CardContent className="w-full p-0 min-h-[360px]">
                        <Table className="min-w-[980px]">
                            <TableHeader>
                                <TableRow className="border-border/60">
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
                                {departments.map((department) => (
                                    <TableRow
                                        key={department.id}
                                        className="border-border/40 cursor-pointer hover:bg-accent/40"
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
                                                    className="h-9 w-9 rounded-xl hover:bg-accent"
                                                    onClick={(e) => { e.stopPropagation(); router.visit(`/organization/departments/${department.id}`); }}
                                                    title="View"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-accent"
                                                    onClick={(e) => { e.stopPropagation(); handleEdit(department); }}
                                                    title="Edit"
                                                >
                                                    <Edit2 className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-destructive/10 text-destructive hover:text-destructive"
                                                    onClick={(e) => { e.stopPropagation(); handleDelete(department); }}
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

            {departments.length === 0 ? <EmptyState title="No departments found." /> : null}

            <Pagination {...list.paginationProps} label="departments" />

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
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ branch_id: '', parent_id: '', manager_id: '', status: '', code: '' })}
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
