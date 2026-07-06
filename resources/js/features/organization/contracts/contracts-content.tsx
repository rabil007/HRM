import { Loader2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { TableBody, TableHeader } from '@/components/ui/table';
import { buildContractEmployeeUrl } from '@/features/organization/contracts/build-contract-employee-url';
import { ContractsImportDialog } from '@/features/organization/contracts/contracts-import-dialog';
import { ContractsSummaryCards } from '@/features/organization/contracts/contracts-summary-cards';
import { ContractsTableRow } from '@/features/organization/contracts/contracts-table-row';
import type { ContractsIndexProps } from '@/features/organization/contracts/types';
import { useContractsIndexFilters } from '@/features/organization/contracts/use-contracts-index-filters';
import { cn } from '@/lib/utils';
import { contracts } from '@/routes/organization';

export function ContractsContent({
    summary,
    lifecycle: initialLifecycle,
    search: initialSearch,
    status: initialStatus,
    payroll_category: initialPayrollCategory,
    contracts: contractRows,
    pagination,
    can,
}: ContractsIndexProps) {
    const [isImportDialogOpen, setIsImportDialogOpen] = useState(false);
    const {
        searchInput,
        isSearching,
        onSearchChange,
        onLifecycleChange,
        onPayrollCategoryChange,
        onPageChange,
    } = useContractsIndexFilters({
        url: contracts.url(),
        initialSearch,
        initialLifecycle,
        initialStatus,
        initialPayrollCategory,
        perPage: pagination.per_page,
    });

    const showOfficeColumns = initialPayrollCategory === 'office';
    const showCrewColumns = initialPayrollCategory === 'crew';

    const minWidth = useMemo(() => {
        if (showOfficeColumns) {
            return 'min-w-[1830px]';
        }

        if (showCrewColumns) {
            return 'min-w-[1670px]';
        }

        return 'min-w-[1430px]';
    }, [showCrewColumns, showOfficeColumns]);

    const backContext = useMemo(
        () => ({
            from: 'index' as const,
            search: initialSearch,
            lifecycle: initialLifecycle,
            status: initialStatus,
            payroll_category: initialPayrollCategory,
            page: pagination.current_page,
        }),
        [
            initialLifecycle,
            initialPayrollCategory,
            initialSearch,
            initialStatus,
            pagination.current_page,
        ],
    );

    return (
        <Main>
            <PageHeader
                title="Contracts"
                right={
                    can.import ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setIsImportDialogOpen(true)}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Import
                        </Button>
                    ) : null
                }
            />

            <ContractsSummaryCards
                summary={summary}
                activeLifecycle={initialLifecycle}
                onSelect={onLifecycleChange}
            />

            <SearchBar
                placeholder="Search employee or labor contract ID..."
                value={searchInput}
                onChange={onSearchChange}
                right={
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex items-center rounded-xl glass-card p-1">
                            {(['', 'office', 'crew'] as const).map((value) => {
                                const label =
                                    value === ''
                                        ? 'All'
                                        : value === 'office'
                                          ? 'Office'
                                          : 'Crew';
                                const isActive =
                                    initialPayrollCategory === value;

                                return (
                                    <Button
                                        key={value || 'all'}
                                        type="button"
                                        variant={isActive ? 'default' : 'ghost'}
                                        className={cn(
                                            'h-10 rounded-lg px-4 text-sm font-medium transition-all',
                                            !isActive && 'hover:bg-accent',
                                        )}
                                        onClick={() =>
                                            onPayrollCategoryChange(value)
                                        }
                                    >
                                        {label}
                                    </Button>
                                );
                            })}
                        </div>
                        {isSearching ? (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}
                    </div>
                }
            />

            {contractRows.length === 0 ? (
                <EmptyState
                    title="No contracts found"
                    description="Try adjusting your search or lifecycle filters."
                />
            ) : (
                <div className="space-y-4">
                    <OrganizationDataTable minWidth={minWidth} compact>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Labor contract ID</DataTableHead>
                                <DataTableHead># Contracts</DataTableHead>
                                <DataTableHead>Payroll category</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead>Start</DataTableHead>
                                <DataTableHead>End</DataTableHead>
                                <DataTableHead>Basic salary</DataTableHead>
                                {showOfficeColumns ? (
                                    <>
                                        <DataTableHead>Housing</DataTableHead>
                                        <DataTableHead>Transport</DataTableHead>
                                        <DataTableHead>
                                            Other allowances
                                        </DataTableHead>
                                    </>
                                ) : null}
                                {showCrewColumns ? (
                                    <>
                                        <DataTableHead>
                                            Supplementary
                                        </DataTableHead>
                                        <DataTableHead>
                                            Site allowance
                                        </DataTableHead>
                                    </>
                                ) : null}
                                <DataTableHead>Profile template</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {contractRows.map((contract) => (
                                <ContractsTableRow
                                    key={contract.id}
                                    contract={contract}
                                    browseHref={buildContractEmployeeUrl(
                                        contract.employee_id,
                                        backContext,
                                    )}
                                    showOfficeColumns={showOfficeColumns}
                                    showCrewColumns={showCrewColumns}
                                />
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    {pagination.last_page > 1 ? (
                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            from={pagination.from}
                            to={pagination.to}
                            total={pagination.total}
                            perPage={pagination.per_page}
                            onPageChange={onPageChange}
                            label="contracts"
                        />
                    ) : null}
                </div>
            )}

            <ContractsImportDialog
                open={isImportDialogOpen}
                onOpenChange={setIsImportDialogOpen}
            />
        </Main>
    );
}
