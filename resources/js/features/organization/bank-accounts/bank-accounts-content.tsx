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
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { TableBody, TableHeader } from '@/components/ui/table';
import { BankAccountsImportDialog } from '@/features/organization/bank-accounts/bank-accounts-import-dialog';
import { BankAccountsSummaryCards } from '@/features/organization/bank-accounts/bank-accounts-summary-cards';
import { BankAccountsTableRow } from '@/features/organization/bank-accounts/bank-accounts-table-row';
import { buildBankAccountEmployeeUrl } from '@/features/organization/bank-accounts/build-bank-account-employee-url';
import type { BankAccountsIndexProps } from '@/features/organization/bank-accounts/types';
import { useBankAccountsIndexFilters } from '@/features/organization/bank-accounts/use-bank-accounts-index-filters';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { bankAccounts } from '@/routes/organization';

export function BankAccountsContent({
    summary,
    search: initialSearch,
    bank_id: initialBankId,
    is_primary: initialIsPrimary,
    payment_method: initialPaymentMethod,
    branch_id: initialBranchId,
    department_id: initialDepartmentId,
    department_tree,
    department_tree_selected_id,
    bank_accounts: bankAccountRows,
    banks,
    pagination,
    can,
}: BankAccountsIndexProps) {
    const [isImportDialogOpen, setIsImportDialogOpen] = useState(false);
    const {
        searchInput,
        isSearching,
        onSearchChange,
        onBankChange,
        onIsPrimaryChange,
        onDepartmentChange,
        onPageChange,
    } = useBankAccountsIndexFilters({
        url: bankAccounts.url(),
        initialSearch,
        initialBankId,
        initialIsPrimary,
        initialPaymentMethod,
        initialBranchId,
        initialDepartmentId,
        perPage: pagination.per_page,
    });

    const backContext = useMemo(
        () => ({
            from: 'index' as const,
            search: initialSearch,
            bank_id: initialBankId,
            is_primary: initialIsPrimary,
            payment_method: initialPaymentMethod,
            branch_id: initialBranchId,
            department_id: initialDepartmentId,
            page: pagination.current_page,
        }),
        [
            initialSearch,
            initialBankId,
            initialIsPrimary,
            initialPaymentMethod,
            initialBranchId,
            initialDepartmentId,
            pagination.current_page,
        ],
    );

    return (
        <Main>
            <PageHeader
                title="Bank Accounts"
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

            <BankAccountsSummaryCards
                summary={summary}
                activeIsPrimary={initialPaymentMethod === 'cash_ansari' ? 'ansari' : initialIsPrimary}
                onSelect={onIsPrimaryChange}
            />

            <SearchBar
                placeholder="Search employee, IBAN or account name..."
                value={searchInput}
                onChange={onSearchChange}
                right={
                    <div className="flex items-center gap-3">
                        {isSearching ? (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}

                        <AppSelect
                            value={initialBankId || ''}
                            onValueChange={(val) => onBankChange(val)}
                            placeholder="All banks"
                            searchPlaceholder="Search bank..."
                            className="w-[200px]"
                        >
                            <AppSelectItem value="">All banks</AppSelectItem>
                            {banks.map((bank) => (
                                <AppSelectItem
                                    key={bank.id}
                                    value={String(bank.id)}
                                >
                                    {bank.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        <DepartmentFilterControls
                            department_tree={department_tree}
                            department_tree_selected_id={department_tree_selected_id}
                            department_tree_selected_position_id={null}
                            onSelectDepartment={onDepartmentChange}
                            onSelectPosition={(_, departmentId) =>
                                onDepartmentChange(departmentId)
                            }
                        />
                    </div>
                }
            />

            {bankAccountRows.length === 0 ? (
                <EmptyState
                    title="No bank accounts found"
                    description="Try adjusting your search or filters."
                />
            ) : (
                <>
                    <OrganizationDataTable
                        minWidth="min-w-[1240px]"
                        tableClassName="table-fixed"
                    >
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="w-[240px]">
                                    Employee
                                </DataTableHead>
                                <DataTableHead className="w-[140px]">
                                    Payment Type
                                </DataTableHead>
                                <DataTableHead className="w-[130px]">
                                    Total Accounts
                                </DataTableHead>
                                <DataTableHead className="w-[160px]">
                                    Bank Name
                                </DataTableHead>
                                <DataTableHead className="w-[180px]">
                                    Account Name
                                </DataTableHead>
                                <DataTableHead className="w-[200px]">
                                    IBAN / Account No
                                </DataTableHead>
                                <DataTableHead className="w-[120px]">
                                    Status
                                </DataTableHead>
                                <DataTableHead className="w-[130px]">
                                    Created Date
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {bankAccountRows.map((row) => (
                                <BankAccountsTableRow
                                    key={row.id}
                                    bankAccount={row}
                                    browseHref={buildBankAccountEmployeeUrl(
                                        row.employee_id,
                                        backContext,
                                    )}
                                />
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    {pagination.last_page > 1 ? (
                        <div className="mt-4">
                            <Pagination
                                currentPage={pagination.current_page}
                                lastPage={pagination.last_page}
                                from={pagination.from}
                                to={pagination.to}
                                total={pagination.total}
                                perPage={pagination.per_page}
                                onPageChange={onPageChange}
                                label="bank accounts"
                            />
                        </div>
                    ) : null}
                </>
            )}

            <BankAccountsImportDialog
                open={isImportDialogOpen}
                onOpenChange={setIsImportDialogOpen}
            />
        </Main>
    );
}
