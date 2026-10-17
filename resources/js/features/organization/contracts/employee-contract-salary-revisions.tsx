import { router, useForm } from '@inertiajs/react';
import { History, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { actions } from '@/lib/design-system';
import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';
import {
    destroy as destroySalaryRevision,
    store as storeSalaryRevision,
    update as updateSalaryRevision,
} from '../../../actions/App/Http/Controllers/Organization/ContractSalaryRevisionController';
import { formatEffectiveMonth } from './format-effective-month';

export type ContractSalaryRevisionItem = {
    id: number;
    version: number;
    effective_from: string | null;
    apply_to_payroll?: boolean;
    payroll_effective_from?: string | null;
    reason: string | null;
    created_at: string | null;
    lines: Array<{
        component_code: string | null;
        component_name: string | null;
        rate_type: string | null;
        amount: number | string | null;
    }>;
};

type Props = {
    employeeId: number;
    contract: EmployeeContractDetails;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    isCrewDaily: boolean;
    isOfficeOrCrewMonthly: boolean;
    hideHeader?: boolean;
    reloadOnly?: string[];
};

function amountToFormValue(value: number | string | null | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

function lineAmount(
    revision: ContractSalaryRevisionItem,
    code: string,
): string {
    const line = revision.lines.find((item) => item.component_code === code);

    return amountToFormValue(line?.amount);
}

function formatMoney(value: number | string | null | undefined): string {
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

function revisionTotal(revision: ContractSalaryRevisionItem): number {
    return revision.lines.reduce((sum, line) => {
        const numeric = Number(line.amount);

        return sum + (Number.isNaN(numeric) ? 0 : numeric);
    }, 0);
}

function currentMonthValue(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function toMonthInputValue(isoDate: string | null): string {
    if (!isoDate) {
        return currentMonthValue();
    }

    return isoDate.slice(0, 7);
}

function toMonthStartDate(monthValue: string): string {
    if (/^\d{4}-\d{2}$/.test(monthValue)) {
        return `${monthValue}-01`;
    }

    return monthValue;
}

function payrollApplicationLabel(revision: ContractSalaryRevisionItem): string {
    if (!revision.apply_to_payroll) {
        return 'Not applied to payroll';
    }

    const monthLabel = formatEffectiveMonth(
        revision.payroll_effective_from ?? revision.effective_from,
    );

    return monthLabel === '—'
        ? 'Applies to payroll'
        : `Applies to payroll from ${monthLabel}`;
}

export function EmployeeContractSalaryRevisions({
    employeeId,
    contract,
    canCreate,
    canUpdate,
    canDelete,
    isCrewDaily,
    isOfficeOrCrewMonthly,
    hideHeader = false,
    reloadOnly = ['contracts', 'employee'],
}: Props): ReactElement {
    const [open, setOpen] = useState(false);
    const [editingRevision, setEditingRevision] =
        useState<ContractSalaryRevisionItem | null>(null);
    const [deleteRevision, setDeleteRevision] =
        useState<ContractSalaryRevisionItem | null>(null);
    const revisions = contract.salary_revisions ?? [];
    const canDeleteRevision = canDelete && revisions.length > 1;
    const showActions = canUpdate || canDeleteRevision;
    const isCrewContract = contract.payroll_category === 'crew';

    const form = useForm({
        effective_from: currentMonthValue(),
        apply_to_payroll: false,
        payroll_effective_from: currentMonthValue(),
        reason: '',
        basic_salary: amountToFormValue(contract.basic_salary),
        housing_allowance: amountToFormValue(contract.housing_allowance),
        transport_allowance: amountToFormValue(contract.transport_allowance),
        other_allowances: amountToFormValue(contract.other_allowances),
        eos_monthly_amount: amountToFormValue(contract.eos_monthly_amount),
        supplementary_allowance: amountToFormValue(
            contract.supplementary_allowance,
        ),
        site_allowance: amountToFormValue(contract.site_allowance),
    });

    const openCreateDialog = (): void => {
        setEditingRevision(null);
        form.setData({
            effective_from: currentMonthValue(),
            apply_to_payroll: false,
            payroll_effective_from: currentMonthValue(),
            reason: '',
            basic_salary: amountToFormValue(contract.basic_salary),
            housing_allowance: amountToFormValue(contract.housing_allowance),
            transport_allowance: amountToFormValue(
                contract.transport_allowance,
            ),
            other_allowances: amountToFormValue(contract.other_allowances),
            eos_monthly_amount: amountToFormValue(contract.eos_monthly_amount),
            supplementary_allowance: amountToFormValue(
                contract.supplementary_allowance,
            ),
            site_allowance: amountToFormValue(contract.site_allowance),
        });
        form.clearErrors();
        setOpen(true);
    };

    const openEditDialog = (revision: ContractSalaryRevisionItem): void => {
        setEditingRevision(revision);
        form.setData({
            effective_from: toMonthInputValue(revision.effective_from),
            apply_to_payroll: Boolean(revision.apply_to_payroll),
            payroll_effective_from: toMonthInputValue(
                revision.payroll_effective_from ?? revision.effective_from,
            ),
            reason: revision.reason ?? '',
            basic_salary: lineAmount(revision, 'BASIC'),
            housing_allowance: lineAmount(revision, 'HOUSING'),
            transport_allowance: lineAmount(revision, 'TRANSPORT'),
            other_allowances: lineAmount(revision, 'OTHER'),
            eos_monthly_amount: lineAmount(revision, 'EOS_PROVISION'),
            supplementary_allowance: lineAmount(
                revision,
                'SUPPLEMENTARY_ALLOWANCE',
            ),
            site_allowance: lineAmount(revision, 'SITE_ALLOWANCE'),
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (): void => {
        const options = {
            preserveScroll: true,
            only: reloadOnly,
            onSuccess: () => {
                setOpen(false);
                setEditingRevision(null);
                form.reset();
            },
        };

        form.transform((data) => {
            const applyToPayroll = isCrewContract
                ? Boolean(data.apply_to_payroll)
                : true;

            return {
                ...data,
                effective_from: toMonthStartDate(data.effective_from),
                apply_to_payroll: applyToPayroll,
                payroll_effective_from: applyToPayroll
                    ? toMonthStartDate(
                          data.payroll_effective_from || data.effective_from,
                      )
                    : null,
            };
        });

        if (editingRevision) {
            form.put(
                updateSalaryRevision.url({
                    employee: employeeId,
                    employeeContract: contract.id,
                    salaryRevision: editingRevision.id,
                }),
                options,
            );

            return;
        }

        form.post(
            storeSalaryRevision.url({
                employee: employeeId,
                employeeContract: contract.id,
            }),
            options,
        );
    };

    return (
        <div className="space-y-3 pt-2">
            {!hideHeader ? (
                <div className="flex items-center gap-2">
                    <History
                        className="size-3.5 text-muted-foreground"
                        aria-hidden
                    />
                    <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        Salary revisions
                    </span>
                    <div className="h-px flex-1 bg-muted/50" />
                    {canCreate ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg"
                            onClick={openCreateDialog}
                        >
                            <Plus className="mr-1 size-3.5" />
                            Add revision
                        </Button>
                    ) : null}
                </div>
            ) : canCreate ? (
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 rounded-lg"
                        onClick={openCreateDialog}
                    >
                        <Plus className="mr-1 size-3.5" />
                        Add revision
                    </Button>
                </div>
            ) : null}

            {revisions.length === 0 ? (
                <p className="text-[11px] text-muted-foreground">
                    No salary revisions yet. Saving a package creates version
                    history used by payroll.
                </p>
            ) : (
                <div className="overflow-hidden rounded-xl border border-border/60">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-muted/40 text-[10px] tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th className="px-3 py-2 font-medium">Ver</th>
                                <th className="px-3 py-2 font-medium">
                                    Effective
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Package
                                </th>
                                <th className="px-3 py-2 font-medium">Total</th>
                                {isCrewContract ? (
                                    <th className="px-3 py-2 font-medium">
                                        Payroll
                                    </th>
                                ) : null}
                                <th className="px-3 py-2 font-medium">
                                    Reason
                                </th>
                                {showActions ? (
                                    <th className="px-3 py-2 text-right font-medium">
                                        Actions
                                    </th>
                                ) : null}
                            </tr>
                        </thead>
                        <tbody>
                            {revisions.map((revision) => (
                                <tr
                                    key={revision.id}
                                    className="border-t border-border/50"
                                >
                                    <td className="px-3 py-2 font-mono">
                                        v{revision.version}
                                    </td>
                                    <td className="px-3 py-2">
                                        {formatEffectiveMonth(
                                            revision.effective_from,
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="space-y-0.5">
                                            {revision.lines.map((line) => (
                                                <div key={line.component_code}>
                                                    {line.component_code ===
                                                    'EOS_PROVISION'
                                                        ? 'EOS (Monthly)'
                                                        : line.component_name}
                                                    : {formatMoney(line.amount)}
                                                </div>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 font-mono font-semibold tabular-nums">
                                        {formatMoney(revisionTotal(revision))}
                                    </td>
                                    {isCrewContract ? (
                                        <td className="px-3 py-2">
                                            <div className="space-y-0.5">
                                                <div>
                                                    {revision.apply_to_payroll
                                                        ? 'Yes'
                                                        : 'No'}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {payrollApplicationLabel(
                                                        revision,
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                    ) : null}
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {revision.reason || '—'}
                                    </td>
                                    {showActions ? (
                                        <td className="px-3 py-2 text-right">
                                            <EmployeeRecordRowActions
                                                onEdit={
                                                    canUpdate
                                                        ? () =>
                                                              openEditDialog(
                                                                  revision,
                                                              )
                                                        : undefined
                                                }
                                                onDelete={
                                                    canDeleteRevision
                                                        ? () =>
                                                              setDeleteRevision(
                                                                  revision,
                                                              )
                                                        : undefined
                                                }
                                            />
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Dialog
                open={open}
                onOpenChange={(nextOpen) => {
                    setOpen(nextOpen);

                    if (!nextOpen) {
                        setEditingRevision(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRevision
                                ? `Edit salary revision v${editingRevision.version}`
                                : 'Add salary revision'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Revision effective month updates salary history and
                            can mirror the contract. Crew payroll uses a
                            separate Payroll Effective Month only when enabled.
                        </p>
                    </DialogHeader>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="revision_effective_from">
                                Effective month
                            </Label>
                            <Input
                                id="revision_effective_from"
                                type="month"
                                value={form.data.effective_from}
                                onChange={(e) =>
                                    form.setData(
                                        'effective_from',
                                        e.target.value,
                                    )
                                }
                            />
                            {form.errors.effective_from ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.effective_from}
                                </p>
                            ) : null}
                        </div>

                        {isCrewContract ? (
                            <div className="space-y-3 sm:col-span-2">
                                <label
                                    htmlFor="revision_apply_to_payroll"
                                    className="flex items-start gap-2 rounded-lg border border-border/60 px-3 py-2"
                                >
                                    <Checkbox
                                        id="revision_apply_to_payroll"
                                        checked={form.data.apply_to_payroll}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'apply_to_payroll',
                                                checked === true,
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <span className="space-y-0.5">
                                        <span className="block text-sm font-medium">
                                            Apply revised salary to payroll
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            Leave unchecked to keep the revision
                                            in history without changing payable
                                            rates.
                                        </span>
                                    </span>
                                </label>
                                {form.errors.apply_to_payroll ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.apply_to_payroll}
                                    </p>
                                ) : null}

                                {form.data.apply_to_payroll ? (
                                    <div className="space-y-1.5">
                                        <Label htmlFor="revision_payroll_effective_from">
                                            Payroll Effective Month
                                        </Label>
                                        <Input
                                            id="revision_payroll_effective_from"
                                            type="month"
                                            value={
                                                form.data.payroll_effective_from
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'payroll_effective_from',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Applies the full revised package
                                            from the first day of this month.
                                        </p>
                                        {form.errors.payroll_effective_from ? (
                                            <p className="text-xs text-destructive">
                                                {
                                                    form.errors
                                                        .payroll_effective_from
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        <div className="space-y-1.5">
                            <Label htmlFor="revision_basic_salary">
                                Basic salary
                            </Label>
                            <Input
                                id="revision_basic_salary"
                                inputMode="decimal"
                                value={form.data.basic_salary}
                                onChange={(e) =>
                                    form.setData('basic_salary', e.target.value)
                                }
                            />
                            {form.errors.basic_salary ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.basic_salary}
                                </p>
                            ) : null}
                        </div>

                        {isOfficeOrCrewMonthly ? (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="revision_housing">
                                        Housing
                                    </Label>
                                    <Input
                                        id="revision_housing"
                                        inputMode="decimal"
                                        value={form.data.housing_allowance}
                                        onChange={(e) =>
                                            form.setData(
                                                'housing_allowance',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="revision_transport">
                                        Transport
                                    </Label>
                                    <Input
                                        id="revision_transport"
                                        inputMode="decimal"
                                        value={form.data.transport_allowance}
                                        onChange={(e) =>
                                            form.setData(
                                                'transport_allowance',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="revision_other">
                                        Other
                                    </Label>
                                    <Input
                                        id="revision_other"
                                        inputMode="decimal"
                                        value={form.data.other_allowances}
                                        onChange={(e) =>
                                            form.setData(
                                                'other_allowances',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </>
                        ) : null}

                        {contract.payroll_category === 'crew' &&
                        contract.salary_structure === 'monthly' ? (
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="revision_eos">
                                    EOS (Monthly, optional)
                                </Label>
                                <Input
                                    id="revision_eos"
                                    inputMode="decimal"
                                    value={form.data.eos_monthly_amount}
                                    onChange={(e) =>
                                        form.setData(
                                            'eos_monthly_amount',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Monthly EOS is included in salary payments
                                    and prorated for unpaid leave when
                                    applicable.
                                </p>
                                {form.errors.eos_monthly_amount ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.eos_monthly_amount}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        {isCrewDaily ? (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="revision_supplementary">
                                        Supplementary
                                    </Label>
                                    <Input
                                        id="revision_supplementary"
                                        inputMode="decimal"
                                        value={
                                            form.data.supplementary_allowance
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'supplementary_allowance',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="revision_site">
                                        Site allowance
                                    </Label>
                                    <Input
                                        id="revision_site"
                                        inputMode="decimal"
                                        value={form.data.site_allowance}
                                        onChange={(e) =>
                                            form.setData(
                                                'site_allowance',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </>
                        ) : null}

                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="revision_reason">Reason</Label>
                            <Textarea
                                id="revision_reason"
                                rows={2}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
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
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className={actions.dialogPrimary}
                            disabled={form.processing}
                            onClick={submit}
                        >
                            {form.processing
                                ? 'Saving…'
                                : editingRevision
                                  ? 'Save revision'
                                  : 'Apply revision'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDeleteDialog
                open={deleteRevision !== null}
                onOpenChange={(nextOpen) => {
                    if (!nextOpen) {
                        setDeleteRevision(null);
                    }
                }}
                title="Delete salary revision?"
                description={
                    deleteRevision
                        ? `This removes version v${deleteRevision.version}. Current contract rates will be synced from the latest remaining revision.`
                        : ''
                }
                confirmText="Delete"
                onConfirm={() => {
                    if (!deleteRevision) {
                        return;
                    }

                    router.delete(
                        destroySalaryRevision.url({
                            employee: employeeId,
                            employeeContract: contract.id,
                            salaryRevision: deleteRevision.id,
                        }),
                        {
                            preserveScroll: true,
                            only: reloadOnly,
                            onSuccess: () => setDeleteRevision(null),
                            onError: () => setDeleteRevision(null),
                        },
                    );
                }}
                confirmButtonClassName={`${actions.dialogPrimary} bg-destructive text-destructive-foreground hover:bg-destructive/90`}
            />
        </div>
    );
}
