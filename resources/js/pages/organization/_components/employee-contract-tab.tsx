import { router, useForm } from '@inertiajs/react';
import {
    Banknote,
    CalendarDays,
    FileText,
    MessageSquare,
    Plus,
} from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    destroy as destroyContract,
    store as storeContract,
    update as updateContract,
} from '@/actions/App/Http/Controllers/Organization/EmployeeContractController';
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
import { formatSalaryStructure } from '@/features/organization/contracts/contracts-format';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import { actions } from '@/lib/design-system';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
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
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { normalizeDecimalFieldValue } from '@/pages/organization/_lib/normalize-decimal-field-value';
import {
    createTemplateFieldVisibility,
    getTemplateRequiredFieldKeys,
    isEmptyTemplateFieldValue,
    isTemplateFieldRequired,
    omitHiddenTemplateRecordFields,
} from '@/pages/organization/_lib/template-field-visibility';
import type {
    EmployeeContractDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

const CONTRACTS_RELOAD = {
    preserveScroll: true,
    only: ['contracts', 'employee'],
} as const;

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
};

const PAYROLL_CATEGORY_LABELS: Record<string, string> = {
    office: 'Office',
    crew: 'Crew',
};

const DEFAULT_CONTRACT_REQUIRED_FIELDS = [
    'start_date',
    'status',
] as const;

type ContractFormFieldProps = {
    field: string;
    highlightMissing: boolean;
    children: ReactNode;
};

function ContractFormField({
    field,
    highlightMissing,
    children,
}: ContractFormFieldProps): ReactElement {
    return (
        <div
            data-contract-field={field}
            className={cn(
                'space-y-1.5 rounded-xl',
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
        >
            {children}
            {highlightMissing ? (
                <p className="text-xs text-rose-400">Required</p>
            ) : null}
        </div>
    );
}

function RequiredIndicator({ show }: { show: boolean }): ReactElement | null {
    if (!show) {
        return null;
    }

    return <span className="text-red-400"> *</span>;
}

export type EmployeeContractTabProps = {
    employeeId: number | null;
    contracts: EmployeeContractDetails[];
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    ensureEmployee?: () => Promise<number>;
    templateContractFields?: Record<string, TemplateFieldConfig> | null;
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

function formatMoney(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const numeric = Number(value);

    if (Number.isNaN(numeric)) {
        return String(value);
    }

    return numeric.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function contractTotalSalary(row: EmployeeContractDetails): number {
    return [
        row.basic_salary,
        row.housing_allowance,
        row.transport_allowance,
        row.other_allowances,
        row.supplementary_allowance,
        row.site_allowance,
    ].reduce(
        (sum, value) => sum + (Number(value) || 0),
        0,
    );
}

function buildContractPayload(
    data: {
        payroll_category: string;
        salary_structure: string;
        start_date: string;
        end_date: string;
        labor_contract_id: string;
        status: string;
        basic_salary: string;
        housing_allowance: string;
        transport_allowance: string;
        other_allowances: string;
        supplementary_allowance: string;
        site_allowance: string;
        note: string;
    },
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
): Record<string, unknown> {
    return omitHiddenTemplateRecordFields(
        {
            payroll_category: data.payroll_category,
            salary_structure: data.payroll_category === 'crew'
                ? data.salary_structure
                : 'monthly',
            start_date: data.start_date,
            end_date: data.end_date === '' ? null : data.end_date,
            labor_contract_id:
                data.labor_contract_id.trim() === ''
                    ? null
                    : data.labor_contract_id.trim(),
            status: data.status,
            basic_salary: normalizeDecimalFieldValue(data.basic_salary),
            housing_allowance: normalizeDecimalFieldValue(
                data.housing_allowance,
            ),
            transport_allowance: normalizeDecimalFieldValue(
                data.transport_allowance,
            ),
            other_allowances: normalizeDecimalFieldValue(data.other_allowances),
            supplementary_allowance: normalizeDecimalFieldValue(
                data.supplementary_allowance,
            ),
            site_allowance: normalizeDecimalFieldValue(data.site_allowance),
            note: data.note.trim() === '' ? null : data.note.trim(),
        },
        templateFields,
    );
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

function CurrencyInput({
    id,
    value,
    onChange,
    placeholder,
    className,
}: {
    id: string;
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    className?: string;
}): ReactElement {
    return (
        <div className="relative">
            <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[11px] font-medium text-muted-foreground select-none">
                AED
            </span>
            <Input
                id={id}
                inputMode="decimal"
                placeholder={placeholder}
                className={cn('h-10 rounded-xl border-border/60 bg-muted/50 pl-10 text-sm tabular-nums', className)}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
}

export function EmployeeContractTab({
    employeeId,
    contracts,
    canCreate,
    canUpdate,
    canDelete,
    ensureEmployee,
    templateContractFields = null,
}: EmployeeContractTabProps): ReactElement {
    const canMutateContracts = canCreate || canUpdate || canDelete;
    const showField = useMemo(
        () => createTemplateFieldVisibility(templateContractFields),
        [templateContractFields],
    );

    const requiredFields = useMemo(
        () =>
            getTemplateRequiredFieldKeys(templateContractFields, [
                ...DEFAULT_CONTRACT_REQUIRED_FIELDS,
            ]),
        [templateContractFields],
    );

    const isFieldRequired = useCallback(
        (fieldKey: string) =>
            isTemplateFieldRequired(templateContractFields, fieldKey, [
                ...DEFAULT_CONTRACT_REQUIRED_FIELDS,
            ]),
        [templateContractFields],
    );

    const [missingRequiredFields, setMissingRequiredFields] = useState<
        Set<string>
    >(() => new Set());

    const isMissingRequired = useCallback(
        (field: string) => missingRequiredFields.has(field),
        [missingRequiredFields],
    );

    const missingRequiredFieldsList = useMemo(
        () => Array.from(missingRequiredFields),
        [missingRequiredFields],
    );

    const focusMissingField = useCallback((field: string) => {
        requestAnimationFrame(() => {
            document
                .querySelector(`[data-contract-field="${field}"]`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }, []);

    const showContractDetailsSection =
        showField('payroll_category') ||
        showField('salary_structure') ||
        showField('status') ||
        showField('labor_contract_id');
    const showDurationSection =
        showField('start_date') || showField('end_date');
    const showCompensationSection =
        showField('basic_salary') ||
        showField('housing_allowance') ||
        showField('transport_allowance') ||
        showField('other_allowances') ||
        showField('supplementary_allowance') ||
        showField('site_allowance');
    const showNoteSection = showField('note');

    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingContract, setEditingContract] =
        useState<EmployeeContractDetails | null>(null);
    const [deleteContractId, setDeleteContractId] = useState<number | null>(
        null,
    );

    // Ctrl/Cmd+Enter keyboard shortcut to submit the form
    useEffect(() => {
        if (!dialogOpen) {
            return;
        }

        const handleKeyDown = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                submitContract();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dialogOpen]);

    const contractForm = useForm({
        payroll_category: 'office',
        salary_structure: 'monthly',
        start_date: '',
        end_date: '',
        labor_contract_id: '',
        status: 'active',
        basic_salary: '',
        housing_allowance: '',
        transport_allowance: '',
        other_allowances: '',
        supplementary_allowance: '',
        site_allowance: '',
        note: '',
    });

    const isCrewContract = contractForm.data.payroll_category === 'crew';
    const isCrewMonthly =
        isCrewContract && contractForm.data.salary_structure === 'monthly';
    const isCrewDaily =
        isCrewContract && contractForm.data.salary_structure !== 'monthly';

    useEffect(() => {
        if (missingRequiredFields.size === 0) {
            return;
        }

        setMissingRequiredFields((current) => {
            const next = new Set(current);
            let changed = false;

            for (const field of current) {
                const value =
                    contractForm.data[field as keyof typeof contractForm.data];

                if (!isEmptyTemplateFieldValue(value)) {
                    next.delete(field);
                    changed = true;
                }
            }

            return changed ? next : current;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps -- clear highlights when field values change
    }, [contractForm.data]);

    const openCreateDialog = () => {
        contractForm.reset();
        contractForm.clearErrors();
        setMissingRequiredFields(new Set());
        contractForm.setData({
            payroll_category: 'office',
            salary_structure: 'monthly',
            start_date: '',
            end_date: '',
            labor_contract_id: '',
            status: 'active',
            basic_salary: '',
            housing_allowance: '',
            transport_allowance: '',
            other_allowances: '',
            supplementary_allowance: '',
            site_allowance: '',
            note: '',
        });
        setEditingContract(null);
        setDialogOpen(true);
    };

    const openEditDialog = (row: EmployeeContractDetails) => {
        contractForm.clearErrors();
        setMissingRequiredFields(new Set());
        contractForm.setData({
            payroll_category: row.payroll_category ?? 'office',
            salary_structure:
                row.salary_structure ??
                (row.payroll_category === 'crew' ? 'daily' : 'monthly'),
            start_date: row.start_date ?? '',
            end_date: row.end_date ?? '',
            labor_contract_id: row.labor_contract_id ?? '',
            status: row.status ?? 'active',
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
            supplementary_allowance:
                row.supplementary_allowance === null ||
                row.supplementary_allowance === undefined
                    ? ''
                    : String(row.supplementary_allowance),
            site_allowance:
                row.site_allowance === null || row.site_allowance === undefined
                    ? ''
                    : String(row.site_allowance),
            note: row.note ?? '',
        });
        setEditingContract(row);
        setDialogOpen(true);
    };

    const submitContract = async () => {
        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        const missing: string[] = [];

        for (const field of requiredFields) {
            if (!showField(field)) {
                continue;
            }

            const value =
                contractForm.data[field as keyof typeof contractForm.data];

            if (isEmptyTemplateFieldValue(value)) {
                missing.push(field);
            }
        }

        if (missing.length > 0) {
            setMissingRequiredFields(new Set(missing));
            toast.error(
                'Please fill the highlighted required fields before saving.',
            );
            focusMissingField(missing[0]);

            return;
        }

        setMissingRequiredFields(new Set());
        contractForm.clearErrors();

        const payload = buildContractPayload(
            contractForm.data,
            templateContractFields,
        );

        const url = editingContract
            ? updateContract.url({
                  employee: resolvedEmployeeId,
                  employeeContract: editingContract.id,
              })
            : storeContract.url({ employee: resolvedEmployeeId });

        const options = {
            ...CONTRACTS_RELOAD,
            onSuccess: () => {
                setDialogOpen(false);
                contractForm.reset();
                setEditingContract(null);
                setMissingRequiredFields(new Set());
            },
            onError: (errors: Record<string, string | string[]>) => {
                applyRecordFormErrors(contractForm, errors);
            },
        };

        if (editingContract) {
            router.put(url, payload, options);
        } else {
            router.post(url, payload, options);
        }
    };

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
                            onClick={openCreateDialog}
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
                            {showField('housing_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Housing
                                </th>
                            ) : null}
                            {showField('transport_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Transport
                                </th>
                            ) : null}
                            {showField('other_allowances') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Other allowances
                                </th>
                            ) : null}
                            {showField('supplementary_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Supplementary
                                </th>
                            ) : null}
                            {showField('site_allowance') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Site allowance
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
                            {canMutateContracts ? (
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
                                {showField('housing_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(row.housing_allowance)}
                                    </td>
                                ) : null}
                                {showField('transport_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(row.transport_allowance)}
                                    </td>
                                ) : null}
                                {showField('other_allowances') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(row.other_allowances)}
                                    </td>
                                ) : null}
                                {showField('supplementary_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(
                                            row.supplementary_allowance,
                                        )}
                                    </td>
                                ) : null}
                                {showField('site_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground tabular-nums',
                                        )}
                                    >
                                        {formatMoney(row.site_allowance)}
                                    </td>
                                ) : null}
                                {showCompensationSection ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'font-semibold tabular-nums text-foreground',
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
                                {canMutateContracts ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-right',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            onEdit={
                                                canUpdate
                                                    ? () => openEditDialog(row)
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
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>

            <Dialog
                open={dialogOpen}
                onOpenChange={(openDialog) => {
                    setDialogOpen(openDialog);

                    if (!openDialog) {
                        contractForm.reset();
                        contractForm.clearErrors();
                        setEditingContract(null);
                        setMissingRequiredFields(new Set());
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingContract ? 'Edit contract' : 'Add contract'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Fill in the contract details. Fields marked required
                            in your profile template must be completed.
                        </p>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    {showContractDetailsSection ? (
                        <div className="space-y-4 py-1">
                            <div className="flex items-center gap-2">
                                <FileText className="size-3.5 text-muted-foreground" aria-hidden />
                                <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                    Contract details
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('payroll_category') ? (
                                    <ContractFormField
                                        field="payroll_category"
                                        highlightMissing={isMissingRequired(
                                            'payroll_category',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_payroll_category"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'payroll_category',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Payroll category
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'payroll_category',
                                                )}
                                            />
                                        </Label>
                                        <AppSelect
                                            value={
                                                contractForm.data
                                                    .payroll_category
                                            }
                                            onValueChange={(v) =>
                                                contractForm.setData((data) => ({
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
                                        <p className="text-[11px] text-muted-foreground">
                                            Office uses monthly salary; crew uses
                                            daily or monthly structures with
                                            timesheets
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('salary_structure') &&
                                isCrewContract ? (
                                    <ContractFormField
                                        field="salary_structure"
                                        highlightMissing={isMissingRequired(
                                            'salary_structure',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_salary_structure"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'salary_structure',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Salary structure
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'salary_structure',
                                                )}
                                            />
                                        </Label>
                                        <AppSelect
                                            value={
                                                contractForm.data
                                                    .salary_structure
                                            }
                                            onValueChange={(v) =>
                                                contractForm.setData(
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
                                        <p className="text-[11px] text-muted-foreground">
                                            Daily crew uses site and
                                            supplementary rates; monthly crew
                                            uses housing, transport, and other
                                            allowances
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('status') ? (
                                    <ContractFormField
                                        field="status"
                                        highlightMissing={isMissingRequired(
                                            'status',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_status"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired('status') &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Status
                                            <RequiredIndicator
                                                show={isFieldRequired('status')}
                                            />
                                        </Label>
                                        <div className="flex items-center gap-2">
                                            <AppSelect
                                                value={contractForm.data.status}
                                                onValueChange={(v) =>
                                                    contractForm.setData(
                                                        'status',
                                                        v,
                                                    )
                                                }
                                                variant="dark"
                                            >
                                                <AppSelectItem value="active">
                                                    Active
                                                </AppSelectItem>
                                                <AppSelectItem value="ended">
                                                    Ended
                                                </AppSelectItem>
                                            </AppSelect>
                                            <span
                                                className={cn(
                                                    'inline-flex shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium',
                                                    contractStatusClass(
                                                        contractForm.data.status,
                                                    ),
                                                )}
                                            >
                                                {formatStatus(
                                                    contractForm.data.status,
                                                )}
                                            </span>
                                        </div>
                                        <p className="text-[11px] text-muted-foreground">
                                            Current state of this contract
                                        </p>
                                    </ContractFormField>
                                ) : null}
                            </div>
                            {showField('labor_contract_id') ? (
                                <ContractFormField
                                    field="labor_contract_id"
                                    highlightMissing={isMissingRequired(
                                        'labor_contract_id',
                                    )}
                                >
                                    <Label
                                        htmlFor="contract_labor_contract_id"
                                        className={cn(
                                            'text-xs',
                                            isMissingRequired(
                                                'labor_contract_id',
                                            ) && employeeFieldMissingLabelClass,
                                        )}
                                    >
                                        Labor contract ID
                                        <RequiredIndicator
                                            show={isFieldRequired(
                                                'labor_contract_id',
                                            )}
                                        />
                                    </Label>
                                    <Input
                                        id="contract_labor_contract_id"
                                        className={cn(
                                            'h-10 rounded-xl border-border/60 bg-muted/50 font-mono text-sm tracking-wide',
                                            isMissingRequired(
                                                'labor_contract_id',
                                            ) && 'border-rose-500/50',
                                        )}
                                        placeholder="e.g. MOL-2024-00123"
                                        value={
                                            contractForm.data.labor_contract_id
                                        }
                                        onChange={(e) =>
                                            contractForm.setData(
                                                'labor_contract_id',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-[11px] text-muted-foreground">
                                        Reference number from the labor
                                        authority
                                        {isFieldRequired('labor_contract_id')
                                            ? ''
                                            : ' (optional)'}
                                    </p>
                                </ContractFormField>
                            ) : null}
                        </div>
                    ) : null}

                    {showDurationSection ? (
                        <div className="space-y-4 pt-2">
                            <div className="flex items-center gap-2">
                                <CalendarDays className="size-3.5 text-muted-foreground" aria-hidden />
                                <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                    Duration
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('start_date') ? (
                                    <ContractFormField
                                        field="start_date"
                                        highlightMissing={isMissingRequired(
                                            'start_date',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_start_date"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'start_date',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Start date
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'start_date',
                                                )}
                                            />
                                        </Label>
                                        <Input
                                            id="contract_start_date"
                                            type="date"
                                            className={cn(
                                                'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                                isMissingRequired(
                                                    'start_date',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={contractForm.data.start_date}
                                            onChange={(e) =>
                                                contractForm.setData(
                                                    'start_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {contractForm.errors.start_date ? (
                                            <p className="text-xs text-destructive">
                                                {contractForm.errors.start_date}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                When the contract becomes
                                                effective
                                            </p>
                                        )}
                                    </ContractFormField>
                                ) : null}
                                {showField('end_date') ? (
                                    <ContractFormField
                                        field="end_date"
                                        highlightMissing={isMissingRequired(
                                            'end_date',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_end_date"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired('end_date') &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            End date
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'end_date',
                                                )}
                                            />
                                        </Label>
                                        <Input
                                            id="contract_end_date"
                                            type="date"
                                            className={cn(
                                                'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                                isMissingRequired('end_date') &&
                                                    'border-rose-500/50',
                                            )}
                                            value={contractForm.data.end_date}
                                            onChange={(e) =>
                                                contractForm.setData(
                                                    'end_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Leave blank for unlimited contracts
                                            {isFieldRequired('end_date')
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                            </div>
                        </div>
                    ) : null}

                    {showCompensationSection ? (
                        <div className="space-y-4 pt-2">
                            <div className="flex items-center gap-2">
                                <Banknote className="size-3.5 text-muted-foreground" aria-hidden />
                                <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                    Compensation
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('basic_salary') ? (
                                    <ContractFormField
                                        field="basic_salary"
                                        highlightMissing={isMissingRequired(
                                            'basic_salary',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_basic_salary"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'basic_salary',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Basic salary
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'basic_salary',
                                                )}
                                            />
                                        </Label>
                                        <CurrencyInput
                                            id="contract_basic_salary"
                                            placeholder="5,000.00"
                                            className={cn(
                                                isMissingRequired(
                                                    'basic_salary',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={
                                                contractForm.data.basic_salary
                                            }
                                            onChange={(v) =>
                                                contractForm.setData(
                                                    'basic_salary',
                                                    v,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            {isCrewDaily
                                                ? 'Daily basic rate for crew payroll'
                                                : 'Monthly base salary'}
                                            {isFieldRequired('basic_salary')
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('housing_allowance') &&
                                (!isCrewContract || isCrewMonthly) ? (
                                    <ContractFormField
                                        field="housing_allowance"
                                        highlightMissing={isMissingRequired(
                                            'housing_allowance',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_housing_allowance"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'housing_allowance',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Housing allowance
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'housing_allowance',
                                                )}
                                            />
                                        </Label>
                                        <CurrencyInput
                                            id="contract_housing_allowance"
                                            placeholder="1,500.00"
                                            className={cn(
                                                isMissingRequired(
                                                    'housing_allowance',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={
                                                contractForm.data
                                                    .housing_allowance
                                            }
                                            onChange={(v) =>
                                                contractForm.setData(
                                                    'housing_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Monthly housing benefit
                                            {isFieldRequired(
                                                'housing_allowance',
                                            )
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('transport_allowance') &&
                                (!isCrewContract || isCrewMonthly) ? (
                                    <ContractFormField
                                        field="transport_allowance"
                                        highlightMissing={isMissingRequired(
                                            'transport_allowance',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_transport_allowance"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'transport_allowance',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Transport allowance
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'transport_allowance',
                                                )}
                                            />
                                        </Label>
                                        <CurrencyInput
                                            id="contract_transport_allowance"
                                            placeholder="500.00"
                                            className={cn(
                                                isMissingRequired(
                                                    'transport_allowance',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={
                                                contractForm.data
                                                    .transport_allowance
                                            }
                                            onChange={(v) =>
                                                contractForm.setData(
                                                    'transport_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Monthly transport benefit
                                            {isFieldRequired(
                                                'transport_allowance',
                                            )
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('other_allowances') &&
                                (!isCrewContract || isCrewMonthly) ? (
                                    <ContractFormField
                                        field="other_allowances"
                                        highlightMissing={isMissingRequired(
                                            'other_allowances',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_other_allowances"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'other_allowances',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Other allowances
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'other_allowances',
                                                )}
                                            />
                                        </Label>
                                        <CurrencyInput
                                            id="contract_other_allowances"
                                            placeholder="200.00"
                                            className={cn(
                                                isMissingRequired(
                                                    'other_allowances',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={
                                                contractForm.data
                                                    .other_allowances
                                            }
                                            onChange={(v) =>
                                                contractForm.setData(
                                                    'other_allowances',
                                                    v,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Any additional monthly allowances
                                            {isFieldRequired('other_allowances')
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('supplementary_allowance') &&
                                (!isCrewContract || isCrewDaily) ? (
                                    <ContractFormField
                                        field="supplementary_allowance"
                                        highlightMissing={isMissingRequired(
                                            'supplementary_allowance',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_supplementary_allowance"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'supplementary_allowance',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Supplementary allowance
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'supplementary_allowance',
                                                )}
                                            />
                                        </Label>
                                        <CurrencyInput
                                            id="contract_supplementary_allowance"
                                            placeholder="428.00"
                                            className={cn(
                                                isMissingRequired(
                                                    'supplementary_allowance',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={
                                                contractForm.data
                                                    .supplementary_allowance
                                            }
                                            onChange={(v) =>
                                                contractForm.setData(
                                                    'supplementary_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Daily supplementary rate (crewing)
                                            {isFieldRequired(
                                                'supplementary_allowance',
                                            )
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                                {showField('site_allowance') &&
                                (!isCrewContract || isCrewDaily) ? (
                                    <ContractFormField
                                        field="site_allowance"
                                        highlightMissing={isMissingRequired(
                                            'site_allowance',
                                        )}
                                    >
                                        <Label
                                            htmlFor="contract_site_allowance"
                                            className={cn(
                                                'text-xs',
                                                isMissingRequired(
                                                    'site_allowance',
                                                ) &&
                                                    employeeFieldMissingLabelClass,
                                            )}
                                        >
                                            Site allowance
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'site_allowance',
                                                )}
                                            />
                                        </Label>
                                        <CurrencyInput
                                            id="contract_site_allowance"
                                            placeholder="715.00"
                                            className={cn(
                                                isMissingRequired(
                                                    'site_allowance',
                                                ) && 'border-rose-500/50',
                                            )}
                                            value={
                                                contractForm.data.site_allowance
                                            }
                                            onChange={(v) =>
                                                contractForm.setData(
                                                    'site_allowance',
                                                    v,
                                                )
                                            }
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Daily site rate (on-site days)
                                            {isFieldRequired('site_allowance')
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    </ContractFormField>
                                ) : null}
                            </div>

                            {/* Live total compensation banner */}
                            {(() => {
                                const fields = [
                                    contractForm.data.basic_salary,
                                    contractForm.data.housing_allowance,
                                    contractForm.data.transport_allowance,
                                    contractForm.data.other_allowances,
                                    contractForm.data.supplementary_allowance,
                                    contractForm.data.site_allowance,
                                ];
                                const hasAnyValue = fields.some(
                                    (f) => f.trim() !== '',
                                );

                                if (!hasAnyValue) {
                                    return (
                                        <p className="text-[11px] text-muted-foreground">
                                            Enter at least one compensation value to calculate the total.
                                        </p>
                                    );
                                }

                                const total = fields.reduce((sum, f) => {
                                    const n = parseFloat(f);

                                    return sum + (Number.isNaN(n) ? 0 : n);
                                }, 0);

                                return (
                                    <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-2.5">
                                        <span className="text-xs text-muted-foreground">
                                            Total compensation
                                        </span>
                                        <span className="font-mono text-sm font-semibold tabular-nums">
                                            AED{' '}
                                            {total.toLocaleString(undefined, {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            })}
                                        </span>
                                    </div>
                                );
                            })()}
                        </div>
                    ) : null}

                    {showNoteSection ? (
                        <div className="space-y-4 pt-2">
                            <div className="flex items-center gap-2">
                                <MessageSquare className="size-3.5 text-muted-foreground" aria-hidden />
                                <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                    Note
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <ContractFormField
                                field="note"
                                highlightMissing={isMissingRequired('note')}
                            >
                                <Label
                                    htmlFor="contract_note"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('note') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Note
                                    <RequiredIndicator
                                        show={isFieldRequired('note')}
                                    />
                                </Label>
                                <Textarea
                                    id="contract_note"
                                    rows={3}
                                    placeholder="Reason for this contract or contract change…"
                                    className={cn(
                                        'min-h-[88px] resize-y rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('note') &&
                                            'border-rose-500/50',
                                    )}
                                    value={contractForm.data.note}
                                    onChange={(e) =>
                                        contractForm.setData(
                                            'note',
                                            e.target.value,
                                        )
                                    }
                                />
                                {contractForm.errors.note ? (
                                    <p className="text-xs text-destructive">
                                        {contractForm.errors.note}
                                    </p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">
                                        Context for new contracts or amendments
                                        {isFieldRequired('note')
                                            ? ''
                                            : ' (optional)'}
                                    </p>
                                )}
                            </ContractFormField>
                        </div>
                    ) : null}

                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className={actions.dialogSecondary}
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <div className="flex items-center gap-2">
                            <span className="hidden text-[10px] text-muted-foreground sm:block">
                                ⌘↵
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                className={actions.dialogPrimary}
                                disabled={contractForm.processing}
                                onClick={submitContract}
                            >
                                {contractForm.processing ? 'Saving…' : 'Save'}
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

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
        </div>
    );
}
