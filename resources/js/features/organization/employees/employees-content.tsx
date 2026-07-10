import { router, usePage } from '@inertiajs/react';
import { Download, Filter, FolderTree, Plus, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { EmployeeDeleteDialog } from '@/features/organization/employees/components/employee-delete-dialog';
import {
    EMPTY_EMPLOYEE_FILTERS,
    EmployeeFiltersSheet,
} from '@/features/organization/employees/components/employee-filters-sheet';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { firstValidationError } from '@/lib/first-validation-error';
import { formatDisplayDate } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import {
    buildEmployeeListQuery,
    buildEmployeeShowUrl,
} from './build-employee-show-url';
import { DepartmentEmployeeTree } from './components/department-employee-tree';
import { EmployeeCard } from './components/employee-card';
import { EmployeeExportDialog } from './components/employee-export-dialog';
import type { EmployeeFilters } from './components/employee-filters-sheet';
import type {
    BankOption,
    CompanyVisaTypeOption,
    CountryOption,
    DepartmentTreeNode,
    Employee,
    EmployeeExportFieldOption,
    GenderOption,
    ApprovalLocationOption,
    ManagerOption,
    PositionOption,
    RankOption,
    ReligionOption,
    RoleOption,
    SssaOption,
    UserOption,
    VisaTypeOption,
} from './types';

export function EmployeesContent({
    employees,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    positions,
    managers,
    users: _users,
    countries,
    religions: _religions,
    genders,
    visa_types,
    company_visa_types,
    approval_locations,
    sssa_options,
    ranks,
    banks: _banks,
    roles,
    export_field_options,
}: {
    employees: Employee[];
    pagination: PaginationMeta;
    search: string;
    filters: EmployeeFilters;
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    visa_types: VisaTypeOption[];
    company_visa_types: CompanyVisaTypeOption[];
    approval_locations: ApprovalLocationOption[];
    sssa_options: SssaOption[];
    ranks: RankOption[];
    banks: BankOption[];
    roles: RoleOption[];
    export_field_options: EmployeeExportFieldOption[];
}) {
    void _users;
    void _religions;
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
    const [isDepartmentsPopoverOpen, setIsDepartmentsPopoverOpen] =
        useState(false);
    const [isExportOpen, setIsExportOpen] = useState(false);
    const [currentEmployee, setCurrentEmployee] = useState<Employee | null>(
        null,
    );

    const filters: EmployeeFilters = {
        department_id: initialFilters.department_id ?? '',
        position_id: initialFilters.position_id ?? '',
        status: initialFilters.status ?? '',
        manager_id: initialFilters.manager_id ?? '',
        gender_id: initialFilters.gender_id ?? '',
        nationality_id: initialFilters.nationality_id ?? '',
        visa_type_id: initialFilters.visa_type_id ?? '',
        company_visa_type_id: initialFilters.company_visa_type_id ?? '',
        rank_id: initialFilters.rank_id ?? '',
        approval_location_id: initialFilters.approval_location_id ?? '',
        sssa_option_id: initialFilters.sssa_option_id ?? '',
        crew_status: initialFilters.crew_status ?? '',
        role_id: initialFilters.role_id ?? '',
    };

    const activeFiltersCount = Object.values(filters).filter(Boolean).length;

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
            position_id: '',
        });
        setIsDepartmentsOpen(false);
        setIsDepartmentsPopoverOpen(false);
    };

    const handlePositionSelect = (positionId: number, departmentId: number) => {
        handleFiltersChange({
            ...filters,
            department_id: String(departmentId),
            position_id: String(positionId),
        });
        setIsDepartmentsOpen(false);
        setIsDepartmentsPopoverOpen(false);
    };

    const departmentTreeSelectionCount =
        initialFilters.department_id || initialFilters.position_id ? 1 : 0;

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
            onError: (errors) => {
                toast.error(
                    firstValidationError(
                        errors,
                        'employee',
                        'This employee could not be deleted.',
                    ),
                );
            },
        });
    };

    const toggleStatus = (employee: Employee, enabled: boolean) => {
        router.put(
            `/organization/employees/${employee.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const exportFilters = useMemo(
        () => buildEmployeeListQuery(initialSearch, initialFilters),
        [initialSearch, initialFilters],
    );

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
                                className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                                onClick={() =>
                                    router.visit(
                                        '/organization/employees/import',
                                    )
                                }
                            >
                                <Upload className="mr-2 h-4 w-4" />
                                Import
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                            onClick={() => setIsExportOpen(true)}
                        >
                            <Download className="mr-2 h-4 w-4" />
                            Export
                        </Button>
                        <Button
                            onClick={handleAdd}
                            className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                        >
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
                        <Popover
                            open={isDepartmentsPopoverOpen}
                            onOpenChange={setIsDepartmentsPopoverOpen}
                        >
                            <PopoverTrigger asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="hidden h-12 rounded-xl glass-card px-5 hover:bg-accent lg:flex"
                                >
                                    <FolderTree className="mr-2 h-4 w-4" />
                                    Departments
                                    {departmentTreeSelectionCount ? (
                                        <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                            {departmentTreeSelectionCount}
                                        </span>
                                    ) : null}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                align="start"
                                className="w-72 glass-card border-border p-3 dark:border-white/6"
                            >
                                <DepartmentEmployeeTree
                                    nodes={department_tree}
                                    selectedDepartmentId={
                                        department_tree_selected_id
                                    }
                                    selectedPositionId={
                                        department_tree_selected_position_id
                                    }
                                    onSelectDepartment={handleDepartmentSelect}
                                    onSelectPosition={handlePositionSelect}
                                />
                            </PopoverContent>
                        </Popover>
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent lg:hidden"
                            onClick={() => setIsDepartmentsOpen(true)}
                        >
                            <FolderTree className="mr-2 h-4 w-4" />
                            Departments
                            {departmentTreeSelectionCount ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    {departmentTreeSelectionCount}
                                </span>
                            ) : null}
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
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
                <div className="grid grid-cols-1 gap-6 p-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    {employees.map((employee) => (
                        <EmployeeCard
                            key={employee.id}
                            employee={employee}
                            showUrl={buildEmployeeShowUrl(
                                employee.id,
                                listQuery,
                            )}
                            onDelete={handleDelete}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[1720px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">
                                Employee
                            </DataTableHead>
                            <DataTableHead>Assignment</DataTableHead>
                            <DataTableHead>Date of hire</DataTableHead>
                            <DataTableHead>Emails</DataTableHead>
                            <DataTableHead>Phones</DataTableHead>
                            <DataTableHead>Personal</DataTableHead>
                            <DataTableHead>Emergency</DataTableHead>
                            <DataTableHead>Family</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {employees.map((employee) => {
                            const canToggle =
                                employee.status === 'active' ||
                                employee.status === 'inactive';

                            return (
                                <TableRow
                                    key={employee.id}
                                    className={dataTableBodyRowClass()}
                                    onClick={() =>
                                        router.visit(
                                            buildEmployeeShowUrl(
                                                employee.id,
                                                listQuery,
                                            ),
                                        )
                                    }
                                >
                                    <TableCell
                                        className={dataTableCellPrimaryClass()}
                                    >
                                        <div>{employee.name}</div>
                                        <div className="text-xs text-muted-foreground/70">
                                            {employee.employee_no}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="text-sm">
                                            {employee.branch?.name ?? '—'}
                                        </div>
                                        <div className="text-xs text-muted-foreground/70">
                                            {employee.department?.name ?? '—'}
                                            {employee.position?.title
                                                ? ` • ${employee.position.title}`
                                                : ''}
                                        </div>
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'text-sm whitespace-nowrap',
                                        )}
                                    >
                                        {formatDisplayDate(employee.hire_date)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="truncate text-sm">
                                            {employee.work_email ?? '—'}
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground/70">
                                            {employee.personal_email ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="text-sm">
                                            {employee.phone ?? '—'}
                                        </div>
                                        <div className="text-xs text-muted-foreground/70">
                                            {employee.phone_home_country ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="text-sm">
                                            {employee.gender_ref?.name ?? '—'}
                                            {employee.marital_status
                                                ? ` • ${employee.marital_status.charAt(0).toUpperCase() + employee.marital_status.slice(1)}`
                                                : ''}
                                        </div>
                                        <div className="text-xs text-muted-foreground/70">
                                            {formatDisplayDate(
                                                employee.date_of_birth,
                                            )}
                                            {employee.place_of_birth
                                                ? ` • ${employee.place_of_birth}`
                                                : ''}
                                        </div>
                                        <div className="text-xs text-muted-foreground/70">
                                            {employee.religion_ref?.name ?? '—'}
                                            {employee.nationality_ref?.name
                                                ? ` • ${employee.nationality_ref.name}`
                                                : ''}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="text-sm">
                                            {employee.emergency_contact ?? '—'}
                                        </div>
                                        <div className="text-xs text-muted-foreground/70">
                                            {employee.emergency_phone ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="text-sm">
                                            {employee.spouse_name ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {canToggle ? (
                                            <div className="flex items-center gap-2">
                                                <Switch
                                                    checked={
                                                        employee.status ===
                                                        'active'
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleStatus(
                                                            employee,
                                                            checked,
                                                        )
                                                    }
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                />
                                                <span className="text-xs text-muted-foreground/80">
                                                    {employee.status ===
                                                    'active'
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="text-xs text-muted-foreground/80">
                                                {employee.status}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={dataTableActionsCellClass()}
                                    >
                                        <ListTableCrudActions
                                            showEdit={false}
                                            viewHref={buildEmployeeShowUrl(
                                                employee.id,
                                                listQuery,
                                            )}
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

            {employees.length === 0 ? (
                <EmptyState title="No employees found." />
            ) : null}

            <Pagination {...list.paginationProps} label="employees" />

            <Sheet open={isDepartmentsOpen} onOpenChange={setIsDepartmentsOpen}>
                <SheetContent
                    side="left"
                    className="w-[min(100%,280px)] border-r glass-card border-border p-0 dark:border-white/6"
                >
                    <SheetHeader className="border-b border-border px-4 py-4 text-left dark:border-white/6">
                        <SheetTitle className="text-base">
                            Departments
                        </SheetTitle>
                    </SheetHeader>
                    <div className="overflow-y-auto p-4">
                        <DepartmentEmployeeTree
                            nodes={department_tree}
                            selectedDepartmentId={department_tree_selected_id}
                            selectedPositionId={
                                department_tree_selected_position_id
                            }
                            onSelectDepartment={handleDepartmentSelect}
                            onSelectPosition={handlePositionSelect}
                        />
                    </div>
                </SheetContent>
            </Sheet>

            <EmployeeFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange(EMPTY_EMPLOYEE_FILTERS)}
                positions={positions}
                managers={managers}
                genders={genders}
                countries={countries}
                visaTypes={visa_types}
                companyVisaTypes={company_visa_types}
                approvalLocations={approval_locations}
                sssaOptions={sssa_options}
                ranks={ranks}
                roles={roles}
            />

            <EmployeeDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                employee={currentEmployee}
                onConfirm={confirmDelete}
            />

            <EmployeeExportDialog
                open={isExportOpen}
                onOpenChange={setIsExportOpen}
                fieldOptions={export_field_options}
                filters={exportFilters}
                exportUrl="/organization/employees/export"
            />
        </Main>
    );
}
