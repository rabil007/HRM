import { router } from '@inertiajs/react';
import { Eye, Filter, Plus, Trash2 } from 'lucide-react';
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
import { EmployeeCard } from './components/employee-card';
import { EmployeeDeleteDialog } from './components/employee-delete-dialog';
import { EmployeeFiltersSheet } from './components/employee-filters-sheet';
import type { EmployeeFilters } from './components/employee-filters-sheet';
import type {
    BankOption,
    BranchOption,
    CountryOption,
    DepartmentOption,
    Employee,
    GenderOption,
    ManagerOption,
    PositionOption,
    ReligionOption,
    UserOption,
} from './types';

const emptyFilters: EmployeeFilters = {
    branch_id: '',
    department_id: '',
    position_id: '',
    status: '',
};

export function EmployeesContent({
    employees,
    branches,
    departments,
    positions,
    managers: _managers,
    users: _users,
    countries: _countries,
    religions: _religions,
    genders: _genders,
    banks: _banks,
}: {
    employees: Employee[];
    branches: BranchOption[];
    departments: DepartmentOption[];
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

    const [view, setView] = useViewPreference('employees:view', 'grid');
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentEmployee, setCurrentEmployee] = useState<Employee | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<EmployeeFilters>(emptyFilters);

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
                onError: () => {
                    toast.error('Failed to update status. Please try again.');
                },
            },
        );
    };

    const filteredEmployees = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return employees.filter((e) => {
            if (filters.branch_id && String(e.branch?.id ?? '') !== filters.branch_id) {
                return false;
            }

            if (filters.department_id && String(e.department?.id ?? '') !== filters.department_id) {
                return false;
            }

            if (filters.position_id && String(e.position?.id ?? '') !== filters.position_id) {
                return false;
            }

            if (filters.status && (e.status ?? '') !== filters.status) {
                return false;
            }

            if (!query) {
                return true;
            }

            return (
                e.name.toLowerCase().includes(query) ||
                e.employee_no.toLowerCase().includes(query) ||
                (e.work_email ?? '').toLowerCase().includes(query) ||
                (e.phone ?? '').toLowerCase().includes(query) ||
                (e.branch?.name ?? '').toLowerCase().includes(query) ||
                (e.department?.name ?? '').toLowerCase().includes(query) ||
                (e.position?.title ?? '').toLowerCase().includes(query)
            );
        });
    }, [employees, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [filters.branch_id, filters.department_id, filters.position_id, filters.status].filter(Boolean).length;
    }, [filters]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.branch_id) {
            params.set('branch_id', filters.branch_id);
        }

        if (filters.department_id) {
            params.set('department_id', filters.department_id);
        }

        if (filters.position_id) {
            params.set('position_id', filters.position_id);
        }

        if (filters.status) {
            params.set('status', filters.status);
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
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Employee
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search employees by name, employee no, email, phone, or assignment..."
                value={searchQuery}
                onChange={setSearchQuery}
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
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 p-1">
                    {filteredEmployees.map((employee) => (
                        <EmployeeCard
                            key={employee.id}
                            employee={employee}
                        />
                    ))}
                </div>
            ) : (
                <Card className="glass-card w-full overflow-hidden">
                    <CardContent className="w-full p-0 min-h-[360px]">
                        <Table className="min-w-[1800px]">
                            <TableHeader>
                                <TableRow className="border-border/60">
                                    <TableHead className="pl-4">Employee</TableHead>
                                    <TableHead>Assignment</TableHead>
                                    <TableHead>Emails</TableHead>
                                    <TableHead>Phones</TableHead>
                                    <TableHead>Personal</TableHead>
                                    <TableHead>Emergency</TableHead>
                                    <TableHead>Documents</TableHead>
                                    <TableHead>Family</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right pr-4">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredEmployees.map((employee) => {
                                    const canToggle = employee.status === 'active' || employee.status === 'inactive';

                                    return (
                                        <TableRow
                                            key={employee.id}
                                            className="border-border/40 cursor-pointer hover:bg-accent/40"
                                            onClick={() => router.visit(`/organization/employees/${employee.id}`)}
                                        >
                                            <TableCell className="pl-4">
                                                <div className="font-semibold">{employee.name}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.employee_no}</div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-sm">{employee.branch?.name ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.department?.name ?? '—'}
                                                    {employee.position?.title ? ` • ${employee.position.title}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-sm truncate">{employee.work_email ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70 truncate">{employee.personal_email ?? '—'}</div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-sm">{employee.phone ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.phone_home_country ?? '—'}</div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-sm">
                                                    {employee.gender_ref?.name ?? '—'}
                                                    {employee.marital_status
                                                        ? ` • ${employee.marital_status === 'married' ? 'Yes' : 'No'}`
                                                        : ''}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.date_of_birth ?? '—'}
                                                    {employee.place_of_birth ? ` • ${employee.place_of_birth}` : ''}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.religion_ref?.name ?? '—'}
                                                    {employee.nationality_ref?.name ? ` • ${employee.nationality_ref.name}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-sm">{employee.emergency_contact ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.emergency_phone ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.emergency_contact_home_country ?? '—'}
                                                    {employee.emergency_phone_home_country ? ` • ${employee.emergency_phone_home_country}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.passport_number ?? '—'}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.emirates_id ? ` • EID ${employee.emirates_id}` : ''}
                                                </div>
                                                <div className="text-xs text-muted-foreground/70">{employee.labor_contract_id ?? '—'}</div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground/80">
                                                <div className="text-sm">{employee.spouse_name ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">{employee.spouse_birthdate ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground/70">
                                                    {employee.dependent_children_count === null || employee.dependent_children_count === undefined
                                                        ? '—'
                                                        : String(employee.dependent_children_count)}
                                                </div>
                                            </TableCell>
                                            <TableCell>
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
                                            <TableCell className="pr-4">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-9 w-9 rounded-xl hover:bg-accent"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            router.visit(`/organization/employees/${employee.id}`);
                                                        }}
                                                        title="View"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-9 w-9 rounded-xl hover:bg-destructive/10 text-destructive hover:text-destructive"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            handleDelete(employee);
                                                        }}
                                                        title="Delete"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}

            {filteredEmployees.length === 0 ? <EmptyState title="No employees found." /> : null}

            <EmployeeFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                value={filters}
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
                branches={branches}
                departments={departments}
                positions={positions}
            />

            <EmployeeDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                employee={currentEmployee}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}

