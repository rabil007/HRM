import { Calculator } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types/pagination';
import type {
    CrewPayrollRecordListItem,
    OfficePayrollRecordListItem,
    PayrollPeriod,
    PayrollRecordListItem,
    SalaryInput,
} from '../types';
import { OfficePayrollRecordsTable } from './office-payroll-records-table';
import { PayrollRecordsTable } from './payroll-records-table';

export type PayrollRecordsBoardWpsSelection = {
    selectedRecordIds: number[];
    allSelected: boolean;
    someSelected: boolean;
    onToggleRecord: (recordId: number) => void;
    onToggleAll: () => void;
};

export type PayrollRecordsBoardProps = {
    period: PayrollPeriod;
    hasPayrollRecords: boolean;
    canGenerate: boolean;
    isGenerationBlocked: boolean;
    generationBlockingReason: string;
    onOpenGenerateDialog: () => void;
    payroll_records: PayrollRecordListItem[];
    payroll_records_monthly: CrewPayrollRecordListItem[];
    activeCrewSalaryStructure: 'daily' | 'monthly';
    salary_inputs_by_employee: Record<string, SalaryInput[]>;
    canManageSalaryInputs: boolean;
    wpsSelection: PayrollRecordsBoardWpsSelection | undefined;
    onManageSalaryInputs: (record: PayrollRecordListItem) => void;
    onRemove: (record: PayrollRecordListItem) => void;
    isPayslipGenerationLive: boolean;
    recordsPagination: PaginationMeta | null;
    monthlyRecordsPagination: PaginationMeta | null;
    onDailyRecordsPageChange: (page: number) => void;
    onMonthlyRecordsPageChange: (page: number) => void;
    onOfficeRecordsPageChange: (page: number) => void;
};

export function PayrollRecordsBoard({
    period,
    hasPayrollRecords,
    canGenerate,
    isGenerationBlocked,
    generationBlockingReason,
    onOpenGenerateDialog,
    payroll_records,
    payroll_records_monthly,
    activeCrewSalaryStructure,
    salary_inputs_by_employee,
    canManageSalaryInputs,
    wpsSelection,
    onManageSalaryInputs,
    onRemove,
    isPayslipGenerationLive,
    recordsPagination,
    monthlyRecordsPagination,
    onDailyRecordsPageChange,
    onMonthlyRecordsPageChange,
    onOfficeRecordsPageChange,
}: PayrollRecordsBoardProps) {
    const emptyDescription = period.supports_timesheets
        ? 'Generate payroll from entered timesheets to review gross and net amounts.'
        : 'Generate payroll to review full monthly salary and leave usage for this period.';

    if (!hasPayrollRecords) {
        return (
            <EmptyState
                title="No payroll records yet"
                description={emptyDescription}
                action={
                    canGenerate ? (
                        <Button
                            className="rounded-xl"
                            onClick={() => onOpenGenerateDialog()}
                        >
                            <Calculator className="mr-2 h-4 w-4" />
                            Generate payroll
                        </Button>
                    ) : isGenerationBlocked ? (
                        <p className="max-w-md text-center text-sm text-muted-foreground">
                            {generationBlockingReason}
                        </p>
                    ) : undefined
                }
            />
        );
    }

    const dailyCrewRecords = payroll_records.filter(
        (record): record is CrewPayrollRecordListItem =>
            record.payroll_category === 'crew',
    );
    const monthlyCrewRecords = payroll_records_monthly;
    const officeRecords = payroll_records.filter(
        (record): record is OfficePayrollRecordListItem =>
            record.payroll_category === 'office',
    );

    return (
        <>
            {period.supports_timesheets ? (
                <div className="space-y-6">
                    {activeCrewSalaryStructure === 'daily' ? (
                        dailyCrewRecords.length > 0 ? (
                            <>
                                <PayrollRecordsTable
                                    records={dailyCrewRecords}
                                    salaryInputsByEmployee={
                                        salary_inputs_by_employee
                                    }
                                    canManageSalaryInputs={
                                        canManageSalaryInputs
                                    }
                                    canRemove={canGenerate}
                                    wpsSelection={wpsSelection}
                                    onManageSalaryInputs={onManageSalaryInputs}
                                    onRemove={onRemove}
                                    isPayslipGenerationLive={
                                        isPayslipGenerationLive
                                    }
                                />
                                {recordsPagination &&
                                recordsPagination.last_page > 1 ? (
                                    <Pagination
                                        currentPage={
                                            recordsPagination.current_page
                                        }
                                        lastPage={recordsPagination.last_page}
                                        perPage={recordsPagination.per_page}
                                        total={recordsPagination.total}
                                        from={recordsPagination.from}
                                        to={recordsPagination.to}
                                        onPageChange={(page) => {
                                            onDailyRecordsPageChange(page);
                                        }}
                                    />
                                ) : null}
                            </>
                        ) : (
                            <EmptyState
                                title="No daily crew payroll records"
                                description="Generate payroll or switch to Monthly to review monthly crew salaries."
                            />
                        )
                    ) : (monthlyRecordsPagination?.total ?? 0) > 0 ? (
                        <>
                            <OfficePayrollRecordsTable
                                records={monthlyCrewRecords.map((record) => ({
                                    ...record,
                                    payroll_category: 'office' as const,
                                }))}
                                salaryInputsByEmployee={
                                    salary_inputs_by_employee
                                }
                                canManageSalaryInputs={canManageSalaryInputs}
                                canRemove={canGenerate}
                                wpsSelection={wpsSelection}
                                onManageSalaryInputs={(record) =>
                                    onManageSalaryInputs({
                                        ...record,
                                        payroll_category: 'crew',
                                    } as CrewPayrollRecordListItem)
                                }
                                onRemove={(record) =>
                                    onRemove({
                                        ...record,
                                        payroll_category: 'crew',
                                    } as CrewPayrollRecordListItem)
                                }
                                isPayslipGenerationLive={
                                    isPayslipGenerationLive
                                }
                            />
                            {monthlyRecordsPagination &&
                            monthlyRecordsPagination.last_page > 1 ? (
                                <Pagination
                                    currentPage={
                                        monthlyRecordsPagination.current_page
                                    }
                                    lastPage={
                                        monthlyRecordsPagination.last_page
                                    }
                                    perPage={monthlyRecordsPagination.per_page}
                                    total={monthlyRecordsPagination.total}
                                    from={monthlyRecordsPagination.from}
                                    to={monthlyRecordsPagination.to}
                                    onPageChange={(page) => {
                                        onMonthlyRecordsPageChange(page);
                                    }}
                                />
                            ) : null}
                        </>
                    ) : (
                        <EmptyState
                            title="No monthly crew payroll records"
                            description="Switch to Daily to review standby, onsite, and overtime payroll."
                        />
                    )}
                </div>
            ) : (
                <OfficePayrollRecordsTable
                    records={officeRecords}
                    salaryInputsByEmployee={salary_inputs_by_employee}
                    canManageSalaryInputs={canManageSalaryInputs}
                    canRemove={canGenerate}
                    wpsSelection={wpsSelection}
                    onManageSalaryInputs={onManageSalaryInputs}
                    onRemove={onRemove}
                    isPayslipGenerationLive={isPayslipGenerationLive}
                />
            )}
            {!period.supports_timesheets && recordsPagination ? (
                <Pagination
                    currentPage={recordsPagination.current_page}
                    lastPage={recordsPagination.last_page}
                    perPage={recordsPagination.per_page}
                    total={recordsPagination.total}
                    from={recordsPagination.from}
                    to={recordsPagination.to}
                    onPageChange={(page) => {
                        onOfficeRecordsPageChange(page);
                    }}
                />
            ) : null}
        </>
    );
}
