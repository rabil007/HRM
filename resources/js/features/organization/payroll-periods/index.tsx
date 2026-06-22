import { useForm, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
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
import { index, store } from '@/actions/App/Http/Controllers/Organization/PayrollPeriodController';
import { PayrollPeriodFormSheet } from './components/payroll-period-form-sheet';
import type { PayrollPeriod, PayrollPeriodFormData, PayrollPeriodPermissions } from './types';
import type { PaginationMeta } from '@/types/pagination';

export function PayrollPeriodsContent({
    periods,
    pagination,
    permissions,
}: {
    periods: PayrollPeriod[];
    pagination: PaginationMeta;
    permissions: PayrollPeriodPermissions;
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);

    const form = useForm<PayrollPeriodFormData>({
        name: '',
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
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Payroll Periods"
                description="Manage monthly pay periods used for crew and office payroll."
                actions={
                    permissions.create ? (
                        <Button onClick={handleAdd} className="rounded-xl">
                            <Plus className="mr-2 h-4 w-4" />
                            New period
                        </Button>
                    ) : null
                }
            />

            {periods.length === 0 ? (
                <EmptyState
                    title="No payroll periods yet"
                    description="Create a draft period to start entering crew timesheets."
                    action={
                        permissions.create ? (
                            <Button onClick={handleAdd} className="rounded-xl">
                                <Plus className="mr-2 h-4 w-4" />
                                New period
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Payment date</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {periods.map((period) => (
                                <TableRow key={period.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>{period.name}</TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {period.start_date} — {period.end_date}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>{period.payment_date}</TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <Badge variant={period.status === 'draft' ? 'secondary' : 'outline'}>
                                            {period.status_label}
                                        </Badge>
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
                onSubmit={handleSubmit}
            />
        </Main>
    );
}
