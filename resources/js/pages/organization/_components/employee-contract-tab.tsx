import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { TabsContent } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
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

const CONTRACT_TYPE_LABELS: Record<string, string> = {
    limited: 'Limited',
    unlimited: 'Unlimited',
    part_time: 'Part time',
    contract: 'Contract',
};

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
    draft: 'Draft',
};

const DEFAULT_CONTRACT_REQUIRED_FIELDS = [
    'contract_type',
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
    canManage: boolean;
    ensureEmployee?: () => Promise<number>;
    templateContractFields?: Record<string, TemplateFieldConfig> | null;
};

function formatContractType(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return CONTRACT_TYPE_LABELS[value] ?? value;
}

function formatStatus(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return STATUS_LABELS[value] ?? value;
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

function buildContractPayload(
    data: {
        contract_type: string;
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
            contract_type: data.contract_type,
            start_date: data.start_date,
            end_date: data.end_date === '' ? null : data.end_date,
            labor_contract_id:
                data.labor_contract_id.trim() === ''
                    ? null
                    : data.labor_contract_id.trim(),
            status: data.status,
            basic_salary: normalizeDecimalFieldValue(data.basic_salary),
            housing_allowance: normalizeDecimalFieldValue(data.housing_allowance),
            transport_allowance: normalizeDecimalFieldValue(data.transport_allowance),
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

export function EmployeeContractTab({
    employeeId,
    contracts,
    canManage,
    ensureEmployee,
    templateContractFields = null,
}: EmployeeContractTabProps): ReactElement {
    const showField = useMemo(
        () => createTemplateFieldVisibility(templateContractFields),
        [templateContractFields],
    );

    const requiredFields = useMemo(
        () =>
            getTemplateRequiredFieldKeys(
                templateContractFields,
                [...DEFAULT_CONTRACT_REQUIRED_FIELDS],
            ),
        [templateContractFields],
    );

    const isFieldRequired = useCallback(
        (fieldKey: string) =>
            isTemplateFieldRequired(
                templateContractFields,
                fieldKey,
                [...DEFAULT_CONTRACT_REQUIRED_FIELDS],
            ),
        [templateContractFields],
    );

    const [missingRequiredFields, setMissingRequiredFields] = useState<Set<string>>(
        () => new Set(),
    );

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
        showField('contract_type') ||
        showField('status') ||
        showField('labor_contract_id');
    const showDurationSection = showField('start_date') || showField('end_date');
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

    const contractForm = useForm({
        contract_type: 'unlimited',
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
            contract_type: 'unlimited',
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
            contract_type: row.contract_type ?? 'unlimited',
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
        <TabsContent value="contract" className="mt-6">
            <EmployeeRecordsPanel
                title="Contracts"
                count={contracts.length}
                isEmpty={contracts.length === 0}
                emptyMessage="No contracts recorded yet."
                actions={
                    canManage ? (
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
                <EmployeeRecordsTable className="min-w-[1480px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {showField('contract_type') ? (
                                <th className={employeeRecordsTableThClass()}>Type</th>
                            ) : null}
                            {showField('status') ? (
                                <th className={employeeRecordsTableThClass()}>Status</th>
                            ) : null}
                            {showField('labor_contract_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Labor contract ID
                                </th>
                            ) : null}
                            {showField('start_date') ? (
                                <th className={employeeRecordsTableThClass()}>Start</th>
                            ) : null}
                            {showField('end_date') ? (
                                <th className={employeeRecordsTableThClass()}>End</th>
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
                            {showField('note') ? (
                                <th className={employeeRecordsTableThClass()}>Note</th>
                            ) : null}
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {contracts.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                {showField('contract_type') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'font-medium text-foreground',
                                        )}
                                    >
                                        {formatContractType(row.contract_type)}
                                    </td>
                                ) : null}
                                {showField('status') ? (
                                    <td className={employeeRecordsTableTdClass()}>
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
                                        title={row.labor_contract_id ?? undefined}
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
                                            'tabular-nums text-muted-foreground',
                                        )}
                                    >
                                        {formatMoney(row.basic_salary)}
                                    </td>
                                ) : null}
                                {showField('housing_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'tabular-nums text-muted-foreground',
                                        )}
                                    >
                                        {formatMoney(row.housing_allowance)}
                                    </td>
                                ) : null}
                                {showField('transport_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'tabular-nums text-muted-foreground',
                                        )}
                                    >
                                        {formatMoney(row.transport_allowance)}
                                    </td>
                                ) : null}
                                {showField('other_allowances') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'tabular-nums text-muted-foreground',
                                        )}
                                    >
                                        {formatMoney(row.other_allowances)}
                                    </td>
                                ) : null}
                                {showField('supplementary_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'tabular-nums text-muted-foreground',
                                        )}
                                    >
                                        {formatMoney(row.supplementary_allowance)}
                                    </td>
                                ) : null}
                                {showField('site_allowance') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'tabular-nums text-muted-foreground',
                                        )}
                                    >
                                        {formatMoney(row.site_allowance)}
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
                                {canManage ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-right',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            onEdit={() => openEditDialog(row)}
                                            onDelete={() => setDeleteContractId(row.id)}
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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingContract ? 'Edit contract' : 'Add contract'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Fill in the contract details. Fields marked required in your
                            profile template must be completed.
                        </p>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    {showContractDetailsSection ? (
                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Contract details</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {showField('contract_type') ? (
                            <ContractFormField
                                field="contract_type"
                                highlightMissing={isMissingRequired('contract_type')}
                            >
                                <Label
                                    htmlFor="contract_type"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('contract_type') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Contract type
                                    <RequiredIndicator show={isFieldRequired('contract_type')} />
                                </Label>
                                <AppSelect
                                    value={contractForm.data.contract_type}
                                    onValueChange={(v) => contractForm.setData('contract_type', v)}
                                    variant="dark"
                                >
                                    <AppSelectItem value="unlimited">Unlimited</AppSelectItem>
                                    <AppSelectItem value="limited">Limited</AppSelectItem>
                                    <AppSelectItem value="part_time">Part time</AppSelectItem>
                                    <AppSelectItem value="contract">Contract</AppSelectItem>
                                </AppSelect>
                                <p className="text-[11px] text-muted-foreground">The nature of the employment term</p>
                            </ContractFormField>
                            ) : null}
                            {showField('status') ? (
                            <ContractFormField
                                field="status"
                                highlightMissing={isMissingRequired('status')}
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
                                    <RequiredIndicator show={isFieldRequired('status')} />
                                </Label>
                                <AppSelect
                                    value={contractForm.data.status}
                                    onValueChange={(v) => contractForm.setData('status', v)}
                                    variant="dark"
                                >
                                    <AppSelectItem value="active">Active</AppSelectItem>
                                    <AppSelectItem value="ended">Ended</AppSelectItem>
                                    <AppSelectItem value="draft">Draft</AppSelectItem>
                                </AppSelect>
                                <p className="text-[11px] text-muted-foreground">Current state of this contract</p>
                            </ContractFormField>
                            ) : null}
                        </div>
                        {showField('labor_contract_id') ? (
                        <ContractFormField
                            field="labor_contract_id"
                            highlightMissing={isMissingRequired('labor_contract_id')}
                        >
                            <Label
                                htmlFor="contract_labor_contract_id"
                                className={cn(
                                    'text-xs',
                                    isMissingRequired('labor_contract_id') &&
                                        employeeFieldMissingLabelClass,
                                )}
                            >
                                Labor contract ID
                                <RequiredIndicator
                                    show={isFieldRequired('labor_contract_id')}
                                />
                            </Label>
                            <Input
                                id="contract_labor_contract_id"
                                className={cn(
                                    'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                    isMissingRequired('labor_contract_id') &&
                                        'border-rose-500/50',
                                )}
                                placeholder="e.g. MOL-2024-00123"
                                value={contractForm.data.labor_contract_id}
                                onChange={(e) => contractForm.setData('labor_contract_id', e.target.value)}
                            />
                            <p className="text-[11px] text-muted-foreground">
                                Reference number from the labor authority
                                {isFieldRequired('labor_contract_id') ? '' : ' (optional)'}
                            </p>
                        </ContractFormField>
                        ) : null}
                    </div>
                    ) : null}

                    {showDurationSection ? (
                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Duration</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {showField('start_date') ? (
                            <ContractFormField
                                field="start_date"
                                highlightMissing={isMissingRequired('start_date')}
                            >
                                <Label
                                    htmlFor="contract_start_date"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('start_date') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Start date
                                    <RequiredIndicator show={isFieldRequired('start_date')} />
                                </Label>
                                <Input
                                    id="contract_start_date"
                                    type="date"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('start_date') && 'border-rose-500/50',
                                    )}
                                    value={contractForm.data.start_date}
                                    onChange={(e) => contractForm.setData('start_date', e.target.value)}
                                />
                                {contractForm.errors.start_date ? (
                                    <p className="text-xs text-destructive">{contractForm.errors.start_date}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">When the contract becomes effective</p>
                                )}
                            </ContractFormField>
                            ) : null}
                            {showField('end_date') ? (
                            <ContractFormField
                                field="end_date"
                                highlightMissing={isMissingRequired('end_date')}
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
                                    <RequiredIndicator show={isFieldRequired('end_date')} />
                                </Label>
                                <Input
                                    id="contract_end_date"
                                    type="date"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('end_date') && 'border-rose-500/50',
                                    )}
                                    value={contractForm.data.end_date}
                                    onChange={(e) => contractForm.setData('end_date', e.target.value)}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Leave blank for unlimited contracts
                                    {isFieldRequired('end_date') ? '' : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                        </div>
                    </div>
                    ) : null}

                    {showCompensationSection ? (
                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Compensation</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {showField('basic_salary') ? (
                            <ContractFormField
                                field="basic_salary"
                                highlightMissing={isMissingRequired('basic_salary')}
                            >
                                <Label
                                    htmlFor="contract_basic_salary"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('basic_salary') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Basic salary
                                    <RequiredIndicator show={isFieldRequired('basic_salary')} />
                                </Label>
                                <Input
                                    id="contract_basic_salary"
                                    inputMode="decimal"
                                    placeholder="e.g. 5000.00"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('basic_salary') && 'border-rose-500/50',
                                    )}
                                    value={contractForm.data.basic_salary}
                                    onChange={(e) => contractForm.setData('basic_salary', e.target.value)}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Monthly base salary in local currency
                                    {isFieldRequired('basic_salary') ? '' : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                            {showField('housing_allowance') ? (
                            <ContractFormField
                                field="housing_allowance"
                                highlightMissing={isMissingRequired('housing_allowance')}
                            >
                                <Label
                                    htmlFor="contract_housing_allowance"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('housing_allowance') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Housing allowance
                                    <RequiredIndicator
                                        show={isFieldRequired('housing_allowance')}
                                    />
                                </Label>
                                <Input
                                    id="contract_housing_allowance"
                                    inputMode="decimal"
                                    placeholder="e.g. 1500.00"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('housing_allowance') &&
                                            'border-rose-500/50',
                                    )}
                                    value={contractForm.data.housing_allowance}
                                    onChange={(e) => contractForm.setData('housing_allowance', e.target.value)}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Monthly housing benefit
                                    {isFieldRequired('housing_allowance') ? '' : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                            {showField('transport_allowance') ? (
                            <ContractFormField
                                field="transport_allowance"
                                highlightMissing={isMissingRequired('transport_allowance')}
                            >
                                <Label
                                    htmlFor="contract_transport_allowance"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('transport_allowance') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Transport allowance
                                    <RequiredIndicator
                                        show={isFieldRequired('transport_allowance')}
                                    />
                                </Label>
                                <Input
                                    id="contract_transport_allowance"
                                    inputMode="decimal"
                                    placeholder="e.g. 500.00"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('transport_allowance') &&
                                            'border-rose-500/50',
                                    )}
                                    value={contractForm.data.transport_allowance}
                                    onChange={(e) => contractForm.setData('transport_allowance', e.target.value)}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Monthly transport benefit
                                    {isFieldRequired('transport_allowance') ? '' : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                            {showField('other_allowances') ? (
                            <ContractFormField
                                field="other_allowances"
                                highlightMissing={isMissingRequired('other_allowances')}
                            >
                                <Label
                                    htmlFor="contract_other_allowances"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('other_allowances') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Other allowances
                                    <RequiredIndicator
                                        show={isFieldRequired('other_allowances')}
                                    />
                                </Label>
                                <Input
                                    id="contract_other_allowances"
                                    inputMode="decimal"
                                    placeholder="e.g. 200.00"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('other_allowances') &&
                                            'border-rose-500/50',
                                    )}
                                    value={contractForm.data.other_allowances}
                                    onChange={(e) => contractForm.setData('other_allowances', e.target.value)}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Any additional monthly allowances
                                    {isFieldRequired('other_allowances') ? '' : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                            {showField('supplementary_allowance') ? (
                            <ContractFormField
                                field="supplementary_allowance"
                                highlightMissing={isMissingRequired('supplementary_allowance')}
                            >
                                <Label
                                    htmlFor="contract_supplementary_allowance"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('supplementary_allowance') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Supplementary allowance
                                    <RequiredIndicator
                                        show={isFieldRequired('supplementary_allowance')}
                                    />
                                </Label>
                                <Input
                                    id="contract_supplementary_allowance"
                                    inputMode="decimal"
                                    placeholder="e.g. 428.00"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('supplementary_allowance') &&
                                            'border-rose-500/50',
                                    )}
                                    value={contractForm.data.supplementary_allowance}
                                    onChange={(e) =>
                                        contractForm.setData(
                                            'supplementary_allowance',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Daily supplementary rate (crewing)
                                    {isFieldRequired('supplementary_allowance')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                            {showField('site_allowance') ? (
                            <ContractFormField
                                field="site_allowance"
                                highlightMissing={isMissingRequired('site_allowance')}
                            >
                                <Label
                                    htmlFor="contract_site_allowance"
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('site_allowance') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Site allowance
                                    <RequiredIndicator
                                        show={isFieldRequired('site_allowance')}
                                    />
                                </Label>
                                <Input
                                    id="contract_site_allowance"
                                    inputMode="decimal"
                                    placeholder="e.g. 715.00"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('site_allowance') &&
                                            'border-rose-500/50',
                                    )}
                                    value={contractForm.data.site_allowance}
                                    onChange={(e) =>
                                        contractForm.setData('site_allowance', e.target.value)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Daily site rate (on-site days)
                                    {isFieldRequired('site_allowance') ? '' : ' (optional)'}
                                </p>
                            </ContractFormField>
                            ) : null}
                        </div>
                    </div>
                    ) : null}

                    {showNoteSection ? (
                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Note</span>
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
                                    isMissingRequired('note') && employeeFieldMissingLabelClass,
                                )}
                            >
                                Note
                                <RequiredIndicator show={isFieldRequired('note')} />
                            </Label>
                            <Textarea
                                id="contract_note"
                                rows={3}
                                placeholder="Reason for this contract or contract change…"
                                className={cn(
                                    'min-h-[88px] resize-y rounded-xl border-border/60 bg-muted/50 text-sm',
                                    isMissingRequired('note') && 'border-rose-500/50',
                                )}
                                value={contractForm.data.note}
                                onChange={(e) => contractForm.setData('note', e.target.value)}
                            />
                            {contractForm.errors.note ? (
                                <p className="text-xs text-destructive">{contractForm.errors.note}</p>
                            ) : (
                                <p className="text-[11px] text-muted-foreground">
                                    Context for new contracts or amendments
                                    {isFieldRequired('note') ? '' : ' (optional)'}
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
                        <Button
                            type="button"
                            size="sm"
                            className={actions.dialogPrimary}
                            disabled={contractForm.processing}
                            onClick={submitContract}
                        >
                            {contractForm.processing ? 'Saving…' : 'Save'}
                        </Button>
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
                description="This contract record will be permanently removed."
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
        </TabsContent>
    );
}