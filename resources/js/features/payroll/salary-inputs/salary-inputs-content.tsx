import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { index as payrollIndex } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { SalaryInputTypeDeleteDialog } from './components/salary-input-type-delete-dialog';
import { SalaryInputTypeFormSheet } from './components/salary-input-type-form-sheet';
import {
    defaultSalaryInputTypeFormData,
    salaryInputTypeToFormData,
    type SalaryInputTypeRecord,
} from './types';

export function SalaryInputsContent({
    salary_input_types,
    pagination,
    search: initialSearch,
}: {
    salary_input_types: SalaryInputTypeRecord[];
    pagination: PaginationMeta;
    search: string;
}) {
    const list = useServerPaginationFilters({
        url: '/payroll/salary-inputs',
        search: initialSearch,
        filters: {},
        pagination,
    });
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentType, setCurrentType] = useState<SalaryInputTypeRecord | null>(null);

    const form = useForm(defaultSalaryInputTypeFormData());

    const handleAdd = () => {
        setCurrentType(null);
        form.reset();
        form.clearErrors();
        form.setData(defaultSalaryInputTypeFormData());
        setIsSheetOpen(true);
    };

    const handleEdit = (type: SalaryInputTypeRecord) => {
        setCurrentType(type);
        form.reset();
        form.clearErrors();
        form.setData(salaryInputTypeToFormData(type));
        setIsSheetOpen(true);
    };

    const handleDelete = (type: SalaryInputTypeRecord) => {
        setCurrentType(type);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentType) {
            return;
        }

        router.delete(`/payroll/salary-inputs/${currentType.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentType(null);
            },
        });
    };

    const toggleStatus = (type: SalaryInputTypeRecord, enabled: boolean) => {
        router.put(
            `/payroll/salary-inputs/${type.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const submit = () => {
        if (currentType) {
            form.put(`/payroll/salary-inputs/${currentType.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/payroll/salary-inputs', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Salary inputs"
                description="Manage addition and deduction types for office payroll. Assign amounts on each pay record, then update the pay run."
                right={
                    <Button onClick={handleAdd} className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20">
                        <Plus className="mr-2 h-4 w-4" />
                        Add type
                    </Button>
                }
            />

            <Card className="glass-card mb-8 border-primary/20 bg-primary/5">
                <CardContent className="flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <p className="text-sm font-semibold">Assign on pay records</p>
                        <p className="text-sm text-muted-foreground">
                            Open an office pay run, go to the Payroll tab, and use the{' '}
                            <strong>+</strong> action on each employee row. Click{' '}
                            <strong>Update payroll</strong> when done.
                        </p>
                    </div>
                    <Button asChild className="shrink-0 rounded-xl">
                        <Link href={payrollIndex.url()}>
                            <Wallet className="mr-2 h-4 w-4" />
                            Go to pay runs
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                </CardContent>
            </Card>

            <SearchBar
                placeholder="Search types by name or code..."
                value={list.searchInput}
                onChange={list.onSearchChange}
            />

            <OrganizationDataTable minWidth="min-w-[860px]">
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead className="pl-5">Name</DataTableHead>
                        <DataTableHead>Code</DataTableHead>
                        <DataTableHead>Category</DataTableHead>
                        <DataTableHead>Used in pay runs</DataTableHead>
                        <DataTableHead>Status</DataTableHead>
                        <DataTableHead className="text-right">Actions</DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {salary_input_types.map((type) => (
                        <TableRow key={type.id} className={dataTableBodyRowClass()}>
                            <TableCell className={dataTableCellPrimaryClass()}>{type.name}</TableCell>
                            <TableCell className={dataTableCellClass()}>{type.code}</TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        type.is_addition
                                            ? 'border-emerald-500/30 text-emerald-700 dark:text-emerald-200'
                                            : 'border-amber-500/30 text-amber-700 dark:text-amber-200',
                                    )}
                                >
                                    {type.is_addition ? 'Addition' : 'Deduction'}
                                </Badge>
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>{type.salary_inputs_count}</TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <div className="flex items-center gap-3">
                                    <Switch
                                        checked={type.status === 'active'}
                                        onCheckedChange={(checked) => toggleStatus(type, checked)}
                                    />
                                    <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                        {type.status}
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell className={dataTableActionsCellClass()}>
                                <ListTableCrudActions
                                    onEdit={() => handleEdit(type)}
                                    onDelete={() => handleDelete(type)}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </OrganizationDataTable>

            {salary_input_types.length === 0 ? <EmptyState title="No salary input types found." /> : null}

            <Pagination {...list.paginationProps} label="types" />

            <SalaryInputTypeFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                salaryInputType={currentType}
                form={form}
                onSubmit={submit}
            />

            <SalaryInputTypeDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                salaryInputType={currentType}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
