import { router, usePage } from '@inertiajs/react';
import { Filter, FolderTree, Plus, Upload } from 'lucide-react';
import { Suspense, lazy, useMemo, useState } from 'react';
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
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { formatDisplayDate } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { buildEmployeeListQuery, buildEmployeeShowUrl } from './build-employee-show-url';
import { DepartmentEmployeeTree } from './components/department-employee-tree';
import { EmployeeCard } from './components/employee-card';
import type { EmployeeFilters } from './components/employee-filters-sheet';
import type {
    BankOption,
    BranchOption,
    CountryOption,
    DepartmentTreeNode,
    Employee,
    GenderOption,
    ManagerOption,
    PositionOption,
    ReligionOption,
    UserOption,
} from './types';

const EmployeeFiltersSheet = lazy(() =>
    import('./components/employee-filters-sheet').then((m) => ({
        default: m.EmployeeFiltersSheet,
    })),
);

const EmployeeDeleteDialog = lazy(() =>
    import('./components/employee-delete-dialog').then((m) => ({
        default: m.EmployeeDeleteDialog,
    })),
);

export function EmployeesContent({
    employees,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    department_tree,
    department_tree_selected_id,
    branches,
    positions,
    managers: _managers,
    users: _users,
    countries: _countries,
    religions: _religions,
    genders: _genders,
    banks: _banks,
}: {
    employees: Employee[];
    pagination: PaginationMeta;
    search: string;
    filters: { branch_id: string; department_id: string; position_id: string; status: string };
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    branches: BranchOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    banks: BankOption[];
}) {
    void _managers;
    void _users;
    void _countries;
    void _religions;
    void _genders;
    void _banks;

    const list = useServerPaginationFilters({
        url: '/organization/employees',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference('employees:view', 'grid');
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [isDepartmentsOpen, setIsDepartmentsOpen] = useState(false);
    const [isDepartmentsPopoverOpen, setIsDepartmentsPopoverOpen] = useState(false);
    const [currentEmployee, setCurrentEmployee] = useState<Employee | null>(null);

    const filters: EmployeeFilters = {
        branch_id: initialFilters.branch_id,
        department_id: initialFilters.department_id,
        position_id: initialFilters.position_id,
        status: initialFilters.status,
    };

    const activeFiltersCount = [
        initialFilters.branch_id,
        initialFilters.department_id,
        initialFilters.position_id,
        initialFilters.status,
    ].filter(Boolean).length;

    const listQuery = useMemo(
        () => buildEmployeeListQuery(initialSearch, initialFilters),
        [initialSearch, initialFilters],
    );

    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const permissions = auth?.permissions ?? [];
    const canImport = permissions.includes('employees.import');

    const handleFiltersChange = (next: EmployeeFilters) => {
        list.applyFilters(next);
    };

    const handleDepartmentSelect = (id: number | null) => {
        handleFiltersChange({
            ...filters,
            department_id: id !== null ? String(id) : '',
        });
        setIsDepartmentsOpen(false);
        setIsDepartmentsPopoverOpen(false);
    };

    const handleAdd = () => {
        router.visit('/organization/employees/create');
    };

    const handleDelete = (employee: Employee) => {
        setCurrentEmployee(employee);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentEmployee) {
return;
}

        router.delete(`/organization/employees/${currentEmployee.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentEmployee(null);
            },
        });
    };

    const toggleStatus = (employee: Employee, enabled: boolean) => {
        router.put(
            `/organization/employees/${employee.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (initialSearch) {
params.set('search', initialSearch);
}

        if (initialFilters.branch_id) {
params.set('branch_id', initialFilters.branch_id);
}

        if (initialFilters.department_id) {
params.set('department_id', initialFilters.department_id);
}

        if (initialFilters.position_id) {
params.set('position_id', initialFilters.position_id);
}

        if (initialFilters.status) {
params.set('status', initialFilters.status);
}

        params.set('format', format);

        return `/organization/employees/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Employees"
                description="Manage employee directory and assignments."
                right={
                    <>
                        {canImport ? (
                            <Button
                                type="button"
                                variant="secondary"
                                className="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                                onClick={() => router.visit('/organization/employees/import')}
                            >
                                <Upload className="mr-2 h-4 w-4" />
                                Import
                            </Button>
                        ) : null}
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                        <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                            <Plus className="mr-2 h-4 w-4" />
                            Add Employee
                        </Button>
                    </>
                }
            />

            <SearchBar
                placeholder="Search employees by name, employee no, email, phone, or assignment..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={
                    <>
                        <ViewToggle value={view} onChange={setView} />
                        {/* Desktop: Popover; Mobile: Sheet */}
                        <Popover open={isDepartmentsPopoverOpen} onOpenChange={setIsDepartmentsPopoverOpen}>
                            <PopoverTrigger asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="glass-card hidden rounded-xl h-12 px-5 hover:bg-accent lg:flex"
                                >
                                    <FolderTree className="mr-2 h-4 w-4" />
                                    Departments
                                    {initialFilters.department_id ? (
                                        <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                            1
                                        </span>
                                    ) : null}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                align="start"
                                className="glass-card w-64 border-white/6 p-3"
                            >
                                <DepartmentEmployeeTree
                                    nodes={department_tree}
                                    selectedId={department_tree_selected_id}
                                    onSelect={(id) => handleDepartmentSelect(id)}
                                />
                            </PopoverContent>
                        </Popover>
                        <Button
                            type="button"
                            variant="secondary"
                            className="glass-card rounded-xl h-12 px-5 hover:bg-accent lg:hidden"
                            onClick={() => setIsDepartmentsOpen(true)}
                        >
                            <FolderTree className="mr-2 h-4 w-4" />
                            Departments
                            {initialFilters.department_id ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    1
                                </span>
                            ) : null}
                        </Button>
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
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 p-1">
                    {employees.map((employee) => (
                        <EmployeeCard
                            key={employee.id}
                            employee={employee}
                            showUrl={buildEmployeeShowUrl(employee.id, listQuery)}
                            onDelete={handleDelete}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[1800px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Employee</DataTableHead>
                            <DataTableHead>Assignment</DataTableHead>
                            <DataTableHead>Emails</DataTableHead>
                            <DataTableHead>Phones</DataTableHead>
                            <DataTableHead>Personal</DataTableHead>
                            <DataTableHead>Emergency</DataTableHead>
                            <DataTableHead>Documents</DataTableHead>
                            <DataTableHead>Family</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                            <TableBody>
                                {employees.map((employee) => {
                                    const canToggle = employee.status === 'active' || employee.status === 'inactive';

                                    return (
                                        <TableRow
                                            key={employee.id}
                                            className={dataTableBodyRowClass()}
                                            onClick={() =>
                                                router.visit(buildEmployeeShowUrl(employee.id, listQuery))
                                            }
                                        >
                                            <TableCell className={dataTableCellPrimaryClass()}>
                                                <div>{employee.name}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.employee_no}</div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-sm">{employee.branch?.name ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.department?.name ?? '—'}
                                                    {employee.position?.title ? ` • ${employee.position.title}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-sm truncate">{employee.work_email ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70 truncate">{employee.personal_email ?? '—'}</div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-sm">{employee.phone ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.phone_home_country ?? '—'}</div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-sm">
                                                    {employee.gender_ref?.name ?? '—'}
                                                    {employee.marital_status
                                                        ? ` • ${employee.marital_status.charAt(0).toUpperCase() + employee.marital_status.slice(1)}`
                                                        : ''}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {formatDisplayDate(employee.date_of_birth)}
                                                    {employee.place_of_birth ? ` • ${employee.place_of_birth}` : ''}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.religion_ref?.name ?? '—'}
                                                    {employee.nationality_ref?.name ? ` • ${employee.nationality_ref.name}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-sm">{employee.emergency_contact ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.emergency_phone ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.emergency_contact_home_country ?? '—'}
                                                    {employee.emergency_phone_home_country ? ` • ${employee.emergency_phone_home_country}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.passport_number ?? '—'}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.emirates_id ? ` • EID ${employee.emirates_id}` : ''}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">{employee.labor_contract_id ?? '—'}</div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <div className="text-sm">{employee.spouse_name ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">{formatDisplayDate(employee.spouse_birthdate)}</div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.dependent_children_count === null || employee.dependent_children_count === undefined
                                                        ? '—'
                                                        : String(employee.dependent_children_count)}
                                                </div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                {canToggle ? (
                                                    <div className="flex items-center gap-2">
                                                        <Switch
                                                            checked={employee.status === 'active'}
                                                            onCheckedChange={(checked) => toggleStatus(employee, checked)}
                                                            onClick={(e) => e.stopPropagation()}
                                                        />
                                                        <span className="text-xs text-muted-foreground/80">
                                                            {employee.status === 'active' ? 'Active' : 'Inactive'}
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground/80">{employee.status}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className={dataTableActionsCellClass()}>
                                                <ListTableCrudActions
                                                    showEdit={false}
                                                    viewHref={buildEmployeeShowUrl(employee.id, listQuery)}
                                                    onDelete={(e) => {
                                                        e.stopPropagation();
                                                        handleDelete(employee);
                                                    }}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                </OrganizationDataTable>
            )}

            {employees.length === 0 ? <EmptyState title="No employees found." /> : null}

            <Pagination {...list.paginationProps} label="employees" />

            <Sheet open={isDepartmentsOpen} onOpenChange={setIsDepartmentsOpen}>
                <SheetContent side="left" className="glass-card w-[min(100%,280px)] border-r border-white/6 p-0">
                    <SheetHeader className="border-b border-white/6 px-4 py-4 text-left">
                        <SheetTitle className="text-base">Departments</SheetTitle>
                    </SheetHeader>
                    <div className="overflow-y-auto p-4">
                        <DepartmentEmployeeTree
                            nodes={department_tree}
                            selectedId={department_tree_selected_id}
                            onSelect={handleDepartmentSelect}
                        />
                    </div>
                </SheetContent>
            </Sheet>

            <Suspense fallback={null}>
                <EmployeeFiltersSheet
                    open={isFiltersOpen}
                    onOpenChange={setIsFiltersOpen}
                    value={filters}
                    onChange={handleFiltersChange}
                    onReset={() => handleFiltersChange({ branch_id: '', department_id: '', position_id: '', status: '' })}
                    branches={branches}
                    positions={positions}
                />

                <EmployeeDeleteDialog
                    open={isDeleteOpen}
                    onOpenChange={setIsDeleteOpen}
                    employee={currentEmployee}
                    onConfirm={confirmDelete}
                />
            </Suspense>
        </Main>
    );
}
