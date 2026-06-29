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
import { Checkbox } from '@/components/ui/checkbox';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { PayrollCategoryBadge } from '../components/payroll-category-badge';
import { formatTimesheetAmount } from '../types';
import type { PayslipListItem, PayslipsFilters } from './types';

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

    const allSelected = records.length > 0 && selectedIds.length === records.length;
    const someSelected = selectedIds.length > 0 && !allSelected;

    const toggleAll = () => {
        if (allSelected) {
            setSelectedIds([]);
        } else {
            setSelectedIds(records.map((r) => r.id));
        }
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

    const hasActions = permissions.generate || permissions.email;

    return (
        <Main>
            <PageHeader
                title="Payslips"
                description="Generate PDF payslips and email them to employees."
            />

            <SearchBar
                value={list.searchInput}
                onChange={list.onSearchChange}
                placeholder="Search employees..."
                className="mb-6"
                right={
                    hasActions && selectedIds.length > 0 ? (
                        <div className="glass-card flex items-center gap-1 rounded-xl p-1">
                            <span className="px-3 text-sm text-muted-foreground">
                                {selectedIds.length} selected
                            </span>
                            {permissions.generate && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-11 rounded-lg px-4 hover:bg-accent"
                                    disabled={processing !== null}
                                    onClick={() => handleBulkAction('generate')}
                                >
                                    <Sparkles className="mr-2 h-4 w-4" />
                                    Generate
                                </Button>
                            )}
                            {permissions.email && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-11 rounded-lg px-4 hover:bg-accent"
                                    disabled={processing !== null}
                                    onClick={() => handleBulkAction('email')}
                                >
                                    <Mail className="mr-2 h-4 w-4" />
                                    Email
                                </Button>
                            )}
                        </div>
                    ) : undefined
                }
            />

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
                                <DataTableHead className="w-12 pl-5">
                                    <Checkbox
                                        checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                                        onCheckedChange={toggleAll}
                                        aria-label="Select all"
                                    />
                                </DataTableHead>
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
                                <TableRow
                                    key={record.id}
                                    className={cn(
                                        dataTableBodyRowClass(false),
                                        'group hover:bg-muted/40 transition-colors duration-200',
                                        selectedIds.includes(record.id) && 'bg-primary/5',
                                    )}
                                >
                                    <TableCell className="w-12 pl-5">
                                        <Checkbox
                                            checked={selectedIds.includes(record.id)}
                                            onCheckedChange={() => toggleSelected(record.id)}
                                            aria-label={`Select ${record.employee.name}`}
                                        />
                                    </TableCell>
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        <div className="font-semibold">{record.employee.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {record.employee.employee_no ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="font-medium">{record.period.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDisplayDate(record.period.start_date)} —{' '}
                                            {formatDisplayDate(record.period.end_date)}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <PayrollCategoryBadge category={record.payroll_category} />
                                    </TableCell>
                                    <TableCell className={`${dataTableCellClass()} text-right font-semibold`}>
                                        {formatTimesheetAmount(record.net_salary)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <Badge
                                            variant={record.has_payslip ? 'default' : 'outline'}
                                            className={cn(
                                                record.has_payslip
                                                    ? 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 border-emerald-500/20'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {record.has_payslip ? 'Generated' : 'Pending'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <div className="flex items-center justify-end gap-1">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg"
                                                    >
                                                        <a
                                                            href={showPayslip.url(record.id)}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label="View payslip"
                                                            className="inline-flex size-8 items-center justify-center rounded-lg hover:bg-accent"
                                                        >
                                                            <FileText className="h-4 w-4 text-muted-foreground transition-colors group-hover:text-foreground" />
                                                        </a>
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>View payslip</TooltipContent>
                                            </Tooltip>
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg"
                                                    >
                                                        <a href={downloadPayslip.url(record.id)}>
                                                            <Download className="h-4 w-4 text-muted-foreground transition-colors group-hover:text-foreground" />
                                                        </a>
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>Download payslip</TooltipContent>
                                            </Tooltip>
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
                        onPageChange={list.goToPage}
                    />
                </>
            )}
        </Main>
    );
}
