import { Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, Plus } from 'lucide-react';
import { useState } from 'react';
import { index, storePeriod } from '@/actions/App/Http/Controllers/Organization/PayrollController';
import { show } from '@/actions/App/Http/Controllers/Organization/PayrollController';
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
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { PaginationMeta } from '@/types/pagination';
import { PayrollPeriodFormSheet } from './components/payroll-period-form-sheet';
import type { PayrollCategoryOption, PayrollHubPermissions, PayrollPeriodFormData, PayrollPeriodListItem } from './types';

export function PayrollIndexContent({
    periods,
    pagination,
    payroll_categories,
    permissions,
}: {
    periods: PayrollPeriodListItem[];
    pagination: PaginationMeta;
    payroll_categories: PayrollCategoryOption[];
    permissions: PayrollHubPermissions;
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);

    const form = useForm<PayrollPeriodFormData>({
        name: '',
        payroll_category: 'crew',
        start_date: '',
        end_date: '',
        payment_date: '',
        notes: '',
    });

    const handleAdd = () => {
        form.reset();
        form.clearErrors();
        setIsSheetOpen(true);
    };

    const handleSubmit = () => {
        form.post(storePeriod.url(), {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Payroll"
                description="Pay periods and crew timesheet entry in one place."
                right={
                    permissions.create_period ? (
                        <Button onClick={handleAdd} className="rounded-xl">
                            <Plus className="mr-2 h-4 w-4" />
                            New pay period
                        </Button>
                    ) : null
                }
            />

            {periods.length === 0 ? (
                <EmptyState
                    title="No pay periods yet"
                    description="Create a draft pay period to start entering crew timesheets."
                    action={
                        permissions.create_period ? (
                            <Button onClick={handleAdd} className="rounded-xl">
                                <Plus className="mr-2 h-4 w-4" />
                                New pay period
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Pay run</DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Payment date</DataTableHead>
                                <DataTableHead>Progress</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead className="text-right">Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {periods.map((period) => (
                                <TableRow key={period.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>
                                        <div className="font-medium">{period.run_label}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {period.employee_count} {period.payroll_category_label.toLowerCase()} employees
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {period.start_date} — {period.end_date}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>{period.payment_date}</TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {period.supports_timesheets
                                            ? `${period.timesheets_progress_label} filled`
                                            : 'Attendance payroll'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <Badge variant={period.status === 'draft' ? 'secondary' : 'outline'}>
                                            {period.status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass}>
                                        {permissions.view_crew_timesheets || permissions.create_period ? (
                                            <Button variant="ghost" size="sm" className="rounded-lg" asChild>
                                                <Link href={show.url(period.id)}>
                                                    Open
                                                    <ChevronRight className="ml-2 h-4 w-4" />
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination
                        currentPage={pagination.current_page}
                        lastPage={pagination.last_page}
                        perPage={pagination.per_page}
                        total={pagination.total}
                        from={pagination.from}
                        to={pagination.to}
                        onPageChange={(page) => {
                            router.get(index.url(), { page }, { preserveState: true, preserveScroll: true });
                        }}
                    />
                </>
            )}

            <PayrollPeriodFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                form={form}
                payrollCategories={payroll_categories}
                onSubmit={handleSubmit}
            />
        </Main>
    );
}
