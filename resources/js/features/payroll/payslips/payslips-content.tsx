import { Link, router } from '@inertiajs/react';
import { Download, FileText, Mail, Sparkles } from 'lucide-react';
import { useState } from 'react';
import {
    download as downloadPayslip,
    email as emailPayslips,
    generate as generatePayslips,
    index as payslipsIndex,
    show as showPayslip,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
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
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate } from '@/lib/format-date';
import { PayrollCategoryBadge } from '../components/payroll-category-badge';
import { formatTimesheetAmount } from '../types';
import type { PayslipListItem, PayslipsFilters } from './types';
import type { PaginationMeta } from '@/types/pagination';

export function PayslipsContent({
    records,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    permissions,
}: {
    records: PayslipListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayslipsFilters;
    permissions: { generate: boolean; email: boolean };
}) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [processing, setProcessing] = useState<'generate' | 'email' | null>(null);

    const list = useServerPaginationFilters({
        url: payslipsIndex.url(),
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });

    const toggleSelected = (id: number) => {
        setSelectedIds((current) =>
            current.includes(id) ? current.filter((item) => item !== id) : [...current, id],
        );
    };

    const handleBulkAction = (action: 'generate' | 'email') => {
        if (selectedIds.length === 0) {
            return;
        }

        setProcessing(action);

        const route = action === 'generate' ? generatePayslips : emailPayslips;

        router.post(
            route.url(),
            { record_ids: selectedIds },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
            },
        );
    };

    return (
        <Main>
            <PageHeader
                title="Payslips"
                description="Generate PDF payslips and email them to employees."
            />

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <SearchBar
                    value={list.searchInput}
                    onChange={list.onSearchChange}
                    placeholder="Search employees..."
                    className="min-w-0 flex-1"
                />
                {permissions.generate ? (
                    <Button
                        variant="outline"
                        disabled={selectedIds.length === 0 || processing !== null}
                        onClick={() => handleBulkAction('generate')}
                    >
                        <Sparkles className="mr-2 h-4 w-4" />
                        Generate selected
                    </Button>
                ) : null}
                {permissions.email ? (
                    <Button
                        disabled={selectedIds.length === 0 || processing !== null}
                        onClick={() => handleBulkAction('email')}
                    >
                        <Mail className="mr-2 h-4 w-4" />
                        Email selected
                    </Button>
                ) : null}
            </div>

            {records.length === 0 ? (
                <EmptyState
                    title="No payroll records"
                    description="Generate payroll for a pay period before creating payslips."
                />
            ) : (
                <>
                    <OrganizationDataTable>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="w-10" />
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Category</DataTableHead>
                                <DataTableHead className="text-right">Net salary</DataTableHead>
                                <DataTableHead>Payslip</DataTableHead>
                                <DataTableHead className={dataTableActionsCellClass()}>Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {records.map((record) => (
                                <TableRow key={record.id} className={dataTableBodyRowClass()}>
                                    <TableCell>
                                        <input
                                            type="checkbox"
                                            checked={selectedIds.includes(record.id)}
                                            onChange={() => toggleSelected(record.id)}
                                        />
                                    </TableCell>
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        <div>{record.employee.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {record.employee.employee_no ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div>{record.period.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDisplayDate(record.period.start_date)} —{' '}
                                            {formatDisplayDate(record.period.end_date)}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <PayrollCategoryBadge category={record.payroll_category} />
                                    </TableCell>
                                    <TableCell className={`${dataTableCellClass()} text-right`}>
                                        {formatTimesheetAmount(record.net_salary)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <Badge variant={record.has_payslip ? 'default' : 'outline'}>
                                            {record.has_payslip ? 'Generated' : 'Pending'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <div className="flex items-center justify-end gap-2">
                                            <Button asChild variant="ghost" size="icon">
                                                <Link href={showPayslip.url(record.id)} target="_blank">
                                                    <FileText className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                            <Button asChild variant="ghost" size="icon">
                                                <a href={downloadPayslip.url(record.id)}>
                                                    <Download className="h-4 w-4" />
                                                </a>
                                            </Button>
                                        </div>
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
                        onPageChange={list.onPageChange}
                    />
                </>
            )}
        </Main>
    );
}
