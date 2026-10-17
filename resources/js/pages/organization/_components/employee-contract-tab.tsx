import type { RequestPayload } from '@inertiajs/core';
import { router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Banknote,
    CalendarDays,
    MessageSquare,
    Plus,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { buildContractShowUrl } from '@/features/organization/contracts/build-contract-show-url';
import { CurrencyInput } from '@/features/organization/contracts/components/contract-form-fields';
import { ContractFormSheet } from '@/features/organization/contracts/components/contract-form-sheet';
import { formatSalaryStructure } from '@/features/organization/contracts/contracts-format';
import {
    contractRowTotalSalary,
    formatContractFormMoney,
    isDailyCrewContractRow,
} from '@/features/organization/contracts/lib/contract-form-data';
import type { ContractEmployeeSummary } from '@/features/organization/contracts/types';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import type { CompanyVisaTypeOption } from '@/features/organization/employees/types';
import { previousIsoCalendarDay } from '@/lib/calendar-date';
import { actions } from '@/lib/design-system';
import { cn } from '@/lib/utils';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import { applyRecordFormErrors } from '@/pages/organization/_lib/apply-record-form-errors';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { normalizeDecimalFieldValue } from '@/pages/organization/_lib/normalize-decimal-field-value';
import { createTemplateFieldVisibility } from '@/pages/organization/_lib/template-field-visibility';
import type {
    EmployeeContractDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';
import {
    destroy as destroyContract,
    transferVisaCompany,
} from '../../../actions/App/Http/Controllers/Organization/EmployeeContractController';

const CONTRACTS_RELOAD = {
    preserveScroll: true,
    only: ['contracts', 'employee'],
};

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
};

const PAYROLL_CATEGORY_LABELS: Record<string, string> = {
    office: 'Office',
    crew: 'Crew',
};

export type EmployeeContractTabProps = {
    employeeId: number | null;
    contracts: EmployeeContractDetails[];
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canCreateSalaryRevisions?: boolean;
    canUpdateSalaryRevisions?: boolean;
    canDeleteSalaryRevisions?: boolean;
    contractShowFrom?: 'profile' | 'employee';
    initialEditContractId?: number | null;
    ensureEmployee?: () => Promise<number>;
    templateContractFields?: Record<string, TemplateFieldConfig> | null;
    companyVisaTypes?: CompanyVisaTypeOption[];
    employeeCompanyVisaTypeId?: number | null;
};

function formatStatus(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return STATUS_LABELS[value] ?? value;
}

function formatPayrollCategory(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return PAYROLL_CATEGORY_LABELS[value] ?? value;
}

function contractStatusClass(status: string | null | undefined): string {
    if (status === 'active') {
        return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
    }

    if (status === 'draft') {
        return 'border-amber-500/25 bg-amber-500/10 text-amber-600 dark:text-amber-300';
    }

    return 'border-zinc-500/25 bg-zinc-500/10 text-muted-foreground';
}

function RequiredIndicator({ show }: { show: boolean }): ReactElement | null {
    if (!show) {
        return null;
    }

    return <span className="text-red-400"> *</span>;
}

export function EmployeeContractTab({
    employeeId,
    contracts,
    canCreate,
    canUpdate,
    canDelete,
    contractShowFrom = 'profile',
    initialEditContractId = null,
    ensureEmployee,
    templateContractFields = null,
    companyVisaTypes = [],
    employeeCompanyVisaTypeId = null,
}: EmployeeContractTabProps): ReactElement {
    const showContractActions = Boolean(employeeId);
    const showField = useMemo(
        () => createTemplateFieldVisibility(templateContractFields),
        [templateContractFields],
    );

    const showCompensationSection =
        showField('basic_salary') ||
        showField('housing_allowance') ||
        showField('transport_allowance') ||
        showField('other_allowances') ||
        showField('eos_monthly_amount') ||
        showField('supplementary_allowance') ||
        showField('site_allowance');
    const hasDailyCrewContracts = contracts.some(isDailyCrewContractRow);
    const hasMonthlyContracts = contracts.some(
        (row) => !isDailyCrewContractRow(row),
    );
    const hasMonthlyCrewContracts = contracts.some(
        (row) =>
            row.payroll_category === 'crew' && !isDailyCrewContractRow(row),
    );

    const [sheetOpen, setSheetOpen] = useState(false);
    const [editingContractId, setEditingContractId] = useState<number | null>(
        null,
    );
    const [deleteContractId, setDeleteContractId] = useState<number | null>(
        null,
    );
    const [handledInitialEditId, setHandledInitialEditId] = useState<
        number | null
    >(null);

    const editingContract = useMemo(() => {
        if (editingContractId === null) {
            return null;
        }

        return contracts.find((row) => row.id === editingContractId) ?? null;
    }, [contracts, editingContractId]);

    if (
        initialEditContractId &&
        canUpdate &&
        handledInitialEditId !== initialEditContractId
    ) {
        const row = contracts.find(
            (contract) => contract.id === initialEditContractId,
        );

        if (row) {
            setHandledInitialEditId(initialEditContractId);
            setEditingContractId(row.id);
            setSheetOpen(true);
        }
    }

    const [transferDialogOpen, setTransferDialogOpen] = useState(false);
    const [transferringContract, setTransferringContract] =
        useState<EmployeeContractDetails | null>(null);

    const transferForm = useForm({
        company_visa_type_id: '',
        transfer_date: '',
        end_date: '',
        labor_contract_id: '',
        payroll_category: 'office',
        salary_structure: 'monthly',
        basic_salary: '',
        housing_allowance: '',
        transport_allowance: '',
        other_allowances: '',
        eos_monthly_amount: '',
        supplementary_allowance: '',
        site_allowance: '',
        note: '',
        reason: '',
    });

    const openTransferDialog = useCallback(
        (row: EmployeeContractDetails) => {
            transferForm.clearErrors();
            transferForm.setData({
                company_visa_type_id: '',
                transfer_date: '',
                end_date: '',
                labor_contract_id: row.labor_contract_id ?? '',
                payroll_category: row.payroll_category ?? 'office',
                salary_structure:
                    row.salary_structure ??
                    (row.payroll_category === 'crew' ? 'daily' : 'monthly'),
                basic_salary:
                    row.basic_salary === null || row.basic_salary === undefined
                        ? ''
                        : String(row.basic_salary),
                housing_allowance:
                    row.housing_allowance === null ||
                    row.housing_allowance === undefined
                        ? ''
                        : String(row.housing_allowance),
                transport_allowance:
                    row.transport_allowance === null ||
                    row.transport_allowance === undefined
                        ? ''
                        : String(row.transport_allowance),
                other_allowances:
                    row.other_allowances === null ||
                    row.other_allowances === undefined
                        ? ''
                        : String(row.other_allowances),
                eos_monthly_amount:
                    row.eos_monthly_amount == null
                        ? ''
                        : String(row.eos_monthly_amount),
                supplementary_allowance:
                    row.supplementary_allowance === null ||
                    row.supplementary_allowance === undefined
                        ? ''
                        : String(row.supplementary_allowance),
                site_allowance:
                    row.site_allowance === null ||
                    row.site_allowance === undefined
                        ? ''
                        : String(row.site_allowance),
                note: '',
                reason: '',
            });
            setTransferringContract(row);
            setTransferDialogOpen(true);
        },
        [transferForm],
    );

    const isTransferCrewContract =
        transferForm.data.payroll_category === 'crew';
    const isTransferCrewMonthly =
        isTransferCrewContract &&
        transferForm.data.salary_structure === 'monthly';
    const isTransferCrewDaily =
        isTransferCrewContract && !isTransferCrewMonthly;

    const oldContractCalculatedEndDate = useMemo(
        () => previousIsoCalendarDay(transferForm.data.transfer_date),
        [transferForm.data.transfer_date],
    );

    const submitTransfer = () => {
        if (!employeeId || !transferringContract) {
            return;
        }

        transferForm.clearErrors();

        const payload: Record<string, unknown> = {
            company_visa_type_id: transferForm.data.company_visa_type_id
                ? Number(transferForm.data.company_visa_type_id)
                : null,
            transfer_date: transferForm.data.transfer_date,
            end_date:
                transferForm.data.end_date === ''
                    ? null
                    : transferForm.data.end_date,
            labor_contract_id:
                transferForm.data.labor_contract_id.trim() === ''
                    ? null
                    : transferForm.data.labor_contract_id.trim(),
            payroll_category: transferForm.data.payroll_category,
            salary_structure:
                transferForm.data.payroll_category === 'crew'
                    ? transferForm.data.salary_structure
                    : 'monthly',
            basic_salary: normalizeDecimalFieldValue(
                transferForm.data.basic_salary,
            ),
            housing_allowance: normalizeDecimalFieldValue(
                transferForm.data.housing_allowance,
            ),
            transport_allowance: normalizeDecimalFieldValue(
                transferForm.data.transport_allowance,
            ),
            other_allowances: normalizeDecimalFieldValue(
                transferForm.data.other_allowances,
            ),
            eos_monthly_amount: isTransferCrewMonthly
                ? normalizeDecimalFieldValue(
                      transferForm.data.eos_monthly_amount,
                  )
                : null,
            supplementary_allowance: normalizeDecimalFieldValue(
                transferForm.data.supplementary_allowance,
            ),
            site_allowance: normalizeDecimalFieldValue(
                transferForm.data.site_allowance,
            ),
            note:
                transferForm.data.note.trim() === ''
                    ? null
                    : transferForm.data.note.trim(),
            reason:
                transferForm.data.reason.trim() === ''
                    ? null
                    : transferForm.data.reason.trim(),
        };

        router.post(
            transferVisaCompany.url({
                employee: employeeId,
                employeeContract: transferringContract.id,
            }),
            payload as RequestPayload,
            {
                ...CONTRACTS_RELOAD,
                onSuccess: () => {
                    setTransferDialogOpen(false);
                    setTransferringContract(null);
                },
                onError: (errors: Record<string, string | string[]>) => {
                    applyRecordFormErrors(transferForm, errors);
                },
            },
        );
    };

    const openCreateSheet = () => {
        setEditingContractId(null);
        setSheetOpen(true);
    };

    const openEditSheet = useCallback((row: EmployeeContractDetails) => {
        setEditingContractId(row.id);
        setSheetOpen(true);
    }, []);

    const employeeSummary: ContractEmployeeSummary | null = employeeId
        ? {
              id: employeeId,
              name: '',
              employee_no: '',
              company_visa_type_id: employeeCompanyVisaTypeId ?? null,
          }
        : null;

    const formatMoney = formatContractFormMoney;
    const isDailyCrewContract = isDailyCrewContractRow;
    const contractTotalSalary = contractRowTotalSalary;

    return (
        <div>
            <EmployeeRecordsPanel
                title="Contracts"
                count={contracts.length}
                isEmpty={contracts.length === 0}
                emptyMessage="No contracts recorded yet."
                actions={
                    canCreate ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={openCreateSheet}
                        >
                            <Plus className="size-3.5" aria-hidden />
                            Add contract
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[1680px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {showField('payroll_category') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Payroll category
                                </th>
                            ) : null}
                            {showField('salary_structure') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Salary structure
                                </th>
                            ) : null}
                            {showField('status') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Status
                                </th>
                            ) : null}
                            {showField('labor_contract_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Labor contract ID
                                </th>
                            ) : null}
                            {showField('company_visa_type_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Sponsor
                                </th>
                            ) : null}
                            {showField('start_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Start
                                </th>
                            ) : null}
                            {showField('end_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    End
                                </th>
                            ) : null}
                            {showField('basic_salary') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Basic salary
                                </th>
                            ) : null}
                            {hasMonthlyContracts &&
                            showField('housing_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Housing
                                </th>
                            ) : null}
                            {hasMonthlyContracts &&
                            showField('transport_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Transport
                                </th>
                            ) : null}
                            {hasMonthlyContracts &&
                            showField('other_allowances') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Other allowances
                                </th>
                            ) : null}
                            {hasDailyCrewContracts &&
                            showField('supplementary_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Supplementary
                                </th>
                            ) : null}
                            {hasDailyCrewContracts &&
                            showField('site_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Site allowance
                                </th>
                            ) : null}
                            {hasMonthlyCrewContracts &&
                            showField('eos_monthly_amount') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    EOS
                                </th>
                            ) : null}
                            {showCompensationSection ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Total salary
                                </th>
                            ) : null}
                            {showField('note') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Note
                                </th>
                            ) : null}
                            {showContractActions ? (
                                <EmployeeRecordsActionsHeader />
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {contracts.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                {showField('payroll_category') ? (
                                    <td
                                        className={employeeRecordsTableTdClass()}
                                    >
                                        <span
                                            className={cn(
                                                'inline-flex rounded-full border px-2 py-0.5 text-xs font-medium',
                                                row.payroll_category === 'crew'
                                                    ? 'border-sky-500/25 bg-sky-500/10 text-sky-600 dark:text-sky-400'
                                                    : 'border-indigo-500/25 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
                                            )}
                                        >
                                            {formatPayrollCategory(
                                                row.payroll_category,
                                            )}
                                        </span>
                                    </td>
                                ) : null}
                                {showField('salary_structure') ? (
                                    <td
                                        className={employeeRecordsTableTdClass()}
                                    >
                                        {formatSalaryStructure(
                                            row.salary_structure ??
                                                (row.payroll_category === 'crew'
                                                    ? 'daily'
                                                    : 'monthly'),
                                        )}
                                    </td>
                                ) : null}
                                {showField('status') ? (
                                    <td
                                        className={employeeRecordsTableTdClass()}
                                    >
                                        <span
                                            className={cn(
                                                'inline-flex rounded-full border px-2 py-0.5 text-xs font-medium',
                                                contractStatusClass(row.status),
                                            )}
                                        >
                                            {formatStatus(row.status)}
                                        </span>
                                    </td>
                                ) : null}
                                {showField('labor_contract_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[160px] truncate font-mono text-xs text-muted-foreground',
                                        )}
                                        title={
                                            row.labor_contract_id ?? undefined
                                        }
                                    >
                                        {row.labor_contract_id || '—'}
                                    </td>
                                ) : null}
                                {showField('company_visa_type_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[160px] truncate text-muted-foreground',
                                        )}
                                        title={
                                            row.company_visa_type?.name ??
                                            undefined
                                        }
                                    >
                                        {row.company_visa_type?.name || '—'}
                                    </td>
                                ) : null}
                                {showField('start_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.start_date)}
                                    </td>
                                ) : null}
                                {showField('end_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.end_date)}
                                    </td>
                                ) : null}
                                {showField('basic_salary') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(row.basic_salary)}
                                    </td>
                                ) : null}
                                {hasMonthlyContracts &&
                                showField('housing_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {isDailyCrewContract(row)
                                            ? '—'
                                            : formatMoney(
                                                  row.housing_allowance,
                                              )}
                                    </td>
                                ) : null}
                                {hasMonthlyContracts &&
                                showField('transport_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {isDailyCrewContract(row)
                                            ? '—'
                                            : formatMoney(
                                                  row.transport_allowance,
                                              )}
                                    </td>
                                ) : null}
                                {hasMonthlyContracts &&
                                showField('other_allowances') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {isDailyCrewContract(row)
                                            ? '—'
                                            : formatMoney(row.other_allowances)}
                                    </td>
                                ) : null}
                                {hasDailyCrewContracts &&
                                showField('supplementary_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {isDailyCrewContract(row)
                                            ? formatMoney(
                                                  row.supplementary_allowance,
                                              )
                                            : '—'}
                                    </td>
                                ) : null}
                                {hasDailyCrewContracts &&
                                showField('site_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {isDailyCrewContract(row)
                                            ? formatMoney(row.site_allowance)
                                            : '—'}
                                    </td>
                                ) : null}
                                {hasMonthlyCrewContracts &&
                                showField('eos_monthly_amount') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {row.payroll_category === 'crew' &&
                                        !isDailyCrewContract(row)
                                            ? formatMoney(
                                                  row.eos_monthly_amount,
                                              )
                                            : '—'}
                                    </td>
                                ) : null}
                                {showCompensationSection ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'font-semibold text-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(contractTotalSalary(row))}
                                    </td>
                                ) : null}
                                {showField('note') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[220px] text-muted-foreground',
                                        )}
                                        title={row.note ?? undefined}
                                    >
                                        <span className="line-clamp-2 text-xs">
                                            {row.note || '—'}
                                        </span>
                                    </td>
                                ) : null}
                                {showContractActions ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-right',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            viewHref={
                                                employeeId
                                                    ? buildContractShowUrl(
                                                          row.id,
                                                          {
                                                              from: contractShowFrom,
                                                              employee_id:
                                                                  employeeId,
                                                          },
                                                      )
                                                    : undefined
                                            }
                                            onEdit={
                                                canUpdate
                                                    ? () => openEditSheet(row)
                                                    : undefined
                                            }
                                            onDelete={
                                                canDelete
                                                    ? () =>
                                                          setDeleteContractId(
                                                              row.id,
                                                          )
                                                    : undefined
                                            }
                                            extraActions={
                                                canCreate &&
                                                row.status === 'active' ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground dark:hover:bg-white/10 dark:hover:text-zinc-100"
                                                        title="Transfer sponsor / new contract"
                                                        aria-label="Transfer sponsor / new contract"
                                                        onClick={() =>
                                                            openTransferDialog(
                                                                row,
                                                            )
                                                        }
                                                    >
                                                        <ArrowLeftRight className="size-4" />
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>
            <ContractFormSheet
                open={sheetOpen}
                onOpenChange={(open) => {
                    setSheetOpen(open);

                    if (!open) {
                        setEditingContractId(null);
                    }
                }}
                mode={editingContract ? 'edit' : 'create'}
                employee={employeeSummary}
                editingContract={editingContract}
                templateContractFields={templateContractFields}
                companyVisaTypes={companyVisaTypes}
                canCreate={canCreate}
                canUpdate={canUpdate}
                employeeId={employeeId}
                ensureEmployee={ensureEmployee}
                reloadOnly={['contracts', 'employee']}
            />

            <EmployeeRecordDeleteDialog
                open={!!deleteContractId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteContractId(null);
                    }
                }}
                title="Remove contract?"
                description="This contract record will be permanently removed. Records linked to pay runs cannot be deleted."
                destroyUrl={
                    deleteContractId && employeeId
                        ? destroyContract.url({
                              employee: employeeId,
                              employeeContract: deleteContractId,
                          })
                        : null
                }
                reloadOptions={CONTRACTS_RELOAD}
            />

            <Dialog
                open={transferDialogOpen}
                onOpenChange={(openDialog) => {
                    setTransferDialogOpen(openDialog);

                    if (!openDialog) {
                        transferForm.reset();
                        transferForm.clearErrors();
                        setTransferringContract(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Transfer sponsor</DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Ends the current contract and opens a new one under
                            the new sponsor.
                        </p>
                    </DialogHeader>

                    <div className="rounded-xl border border-amber-500/25 bg-amber-500/10 px-4 py-3 text-xs text-amber-700 dark:text-amber-300">
                        The existing contract will end one day before the
                        transfer date. A new active contract will begin on the
                        transfer date. Historical payroll and contract records
                        will be preserved.
                    </div>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <ArrowLeftRight
                                className="size-3.5 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                Sponsor
                            </span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">
                                    Current sponsor
                                </Label>
                                <p className="flex h-10 items-center rounded-xl border border-border/60 bg-muted/30 px-3 text-sm text-muted-foreground">
                                    {transferringContract?.company_visa_type
                                        ?.name ?? 'Not set'}
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="transfer_company_visa_type_id"
                                    className="text-xs"
                                >
                                    New sponsor
                                    <RequiredIndicator show />
                                </Label>
                                <AppSelect
                                    value={
                                        transferForm.data.company_visa_type_id
                                    }
                                    onValueChange={(v) =>
                                        transferForm.setData(
                                            'company_visa_type_id',
                                            v,
                                        )
                                    }
                                    variant="dark"
                                    placeholder="Select sponsor"
                                >
                                    {companyVisaTypes.map((option) => (
                                        <AppSelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {transferForm.errors.company_visa_type_id ? (
                                    <p className="text-xs text-destructive">
                                        {
                                            transferForm.errors
                                                .company_visa_type_id
                                        }
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <CalendarDays
                                className="size-3.5 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                Transfer dates
                            </span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="transfer_date"
                                    className="text-xs"
                                >
                                    Transfer effective date
                                    <RequiredIndicator show />
                                </Label>
                                <Input
                                    id="transfer_date"
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={transferForm.data.transfer_date}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'transfer_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                {transferForm.errors.transfer_date ? (
                                    <p className="text-xs text-destructive">
                                        {transferForm.errors.transfer_date}
                                    </p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">
                                        New contract also starts on this date
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">
                                    Old contract end date
                                </Label>
                                <p className="flex h-10 items-center rounded-xl border border-border/60 bg-muted/30 px-3 text-sm text-muted-foreground">
                                    {oldContractCalculatedEndDate
                                        ? formatIsoDateDisplay(
                                              oldContractCalculatedEndDate,
                                          )
                                        : 'Enter a transfer date'}
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="transfer_end_date"
                                    className="text-xs"
                                >
                                    New contract end date
                                </Label>
                                <Input
                                    id="transfer_end_date"
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={transferForm.data.end_date}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'end_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Leave blank for unlimited contracts
                                    (optional)
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="transfer_labor_contract_id"
                                    className="text-xs"
                                >
                                    Labor contract ID
                                </Label>
                                <Input
                                    id="transfer_labor_contract_id"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 font-mono text-sm tracking-wide"
                                    placeholder="e.g. MOL-2024-00123"
                                    value={transferForm.data.labor_contract_id}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'labor_contract_id',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <Banknote
                                className="size-3.5 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                New contract package
                            </span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">
                                    Payroll category
                                </Label>
                                <AppSelect
                                    value={transferForm.data.payroll_category}
                                    onValueChange={(v) =>
                                        transferForm.setData((data) => ({
                                            ...data,
                                            payroll_category: v,
                                            salary_structure:
                                                v === 'crew'
                                                    ? 'daily'
                                                    : 'monthly',
                                        }))
                                    }
                                    variant="dark"
                                >
                                    <AppSelectItem value="office">
                                        Office
                                    </AppSelectItem>
                                    <AppSelectItem value="crew">
                                        Crew
                                    </AppSelectItem>
                                </AppSelect>
                            </div>
                            {isTransferCrewContract ? (
                                <div className="space-y-1.5">
                                    <Label className="text-xs">
                                        Salary structure
                                    </Label>
                                    <AppSelect
                                        value={
                                            transferForm.data.salary_structure
                                        }
                                        onValueChange={(v) =>
                                            transferForm.setData(
                                                'salary_structure',
                                                v,
                                            )
                                        }
                                        variant="dark"
                                    >
                                        <AppSelectItem value="daily">
                                            Daily
                                        </AppSelectItem>
                                        <AppSelectItem value="monthly">
                                            Monthly
                                        </AppSelectItem>
                                    </AppSelect>
                                </div>
                            ) : null}
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="transfer_basic_salary"
                                    className="text-xs"
                                >
                                    Basic salary
                                </Label>
                                <CurrencyInput
                                    id="transfer_basic_salary"
                                    placeholder="5,000.00"
                                    value={transferForm.data.basic_salary}
                                    onChange={(v) =>
                                        transferForm.setData('basic_salary', v)
                                    }
                                />
                            </div>
                            {!isTransferCrewContract ||
                            isTransferCrewMonthly ? (
                                <>
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="transfer_housing_allowance"
                                            className="text-xs"
                                        >
                                            Housing allowance
                                        </Label>
                                        <CurrencyInput
                                            id="transfer_housing_allowance"
                                            value={
                                                transferForm.data
                                                    .housing_allowance
                                            }
                                            onChange={(v) =>
                                                transferForm.setData(
                                                    'housing_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="transfer_transport_allowance"
                                            className="text-xs"
                                        >
                                            Transport allowance
                                        </Label>
                                        <CurrencyInput
                                            id="transfer_transport_allowance"
                                            value={
                                                transferForm.data
                                                    .transport_allowance
                                            }
                                            onChange={(v) =>
                                                transferForm.setData(
                                                    'transport_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="transfer_other_allowances"
                                            className="text-xs"
                                        >
                                            Other allowances
                                        </Label>
                                        <CurrencyInput
                                            id="transfer_other_allowances"
                                            value={
                                                transferForm.data
                                                    .other_allowances
                                            }
                                            onChange={(v) =>
                                                transferForm.setData(
                                                    'other_allowances',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>
                                </>
                            ) : null}
                            {isTransferCrewMonthly ? (
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="transfer_eos_monthly_amount"
                                        className="text-xs"
                                    >
                                        EOS
                                    </Label>
                                    <CurrencyInput
                                        id="transfer_eos_monthly_amount"
                                        value={
                                            transferForm.data.eos_monthly_amount
                                        }
                                        onChange={(v) =>
                                            transferForm.setData(
                                                'eos_monthly_amount',
                                                v,
                                            )
                                        }
                                    />
                                </div>
                            ) : null}
                            {isTransferCrewDaily ? (
                                <>
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="transfer_supplementary_allowance"
                                            className="text-xs"
                                        >
                                            Supplementary allowance
                                        </Label>
                                        <CurrencyInput
                                            id="transfer_supplementary_allowance"
                                            value={
                                                transferForm.data
                                                    .supplementary_allowance
                                            }
                                            onChange={(v) =>
                                                transferForm.setData(
                                                    'supplementary_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="transfer_site_allowance"
                                            className="text-xs"
                                        >
                                            Site allowance
                                        </Label>
                                        <CurrencyInput
                                            id="transfer_site_allowance"
                                            value={
                                                transferForm.data.site_allowance
                                            }
                                            onChange={(v) =>
                                                transferForm.setData(
                                                    'site_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>
                                </>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <MessageSquare
                                className="size-3.5 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                Reason
                            </span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="transfer_reason"
                                className="text-xs"
                            >
                                Transfer reason (optional)
                            </Label>
                            <Textarea
                                id="transfer_reason"
                                rows={3}
                                placeholder="Why is this employee transferring sponsor…"
                                className="min-h-[80px] resize-y rounded-xl border-border/60 bg-muted/50 text-sm"
                                value={transferForm.data.reason}
                                onChange={(e) =>
                                    transferForm.setData(
                                        'reason',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className={actions.dialogSecondary}
                            onClick={() => setTransferDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className={actions.dialogPrimary}
                            disabled={transferForm.processing}
                            onClick={submitTransfer}
                        >
                            {transferForm.processing
                                ? 'Transferring…'
                                : 'Transfer'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
