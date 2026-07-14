import { useForm } from '@inertiajs/react';
import { History, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { store as storeSalaryRevision } from '@/actions/App/Http/Controllers/Organization/ContractSalaryRevisionController';
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
import { actions } from '@/lib/design-system';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';

export type ContractSalaryRevisionItem = {
    id: number;
    version: number;
    effective_from: string | null;
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
    canManage: boolean;
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

export function EmployeeContractSalaryRevisions({
    employeeId,
    contract,
    canManage,
    isCrewDaily,
    isOfficeOrCrewMonthly,
    hideHeader = false,
    reloadOnly = ['contracts', 'employee'],
}: Props): ReactElement {
    const [open, setOpen] = useState(false);
    const revisions = contract.salary_revisions ?? [];

    const form = useForm({
        effective_from: new Date().toISOString().slice(0, 10),
        reason: '',
        basic_salary: amountToFormValue(contract.basic_salary),
        housing_allowance: amountToFormValue(contract.housing_allowance),
        transport_allowance: amountToFormValue(contract.transport_allowance),
        other_allowances: amountToFormValue(contract.other_allowances),
        supplementary_allowance: amountToFormValue(
            contract.supplementary_allowance,
        ),
        site_allowance: amountToFormValue(contract.site_allowance),
    });

    const openDialog = (): void => {
        form.setData({
            effective_from: new Date().toISOString().slice(0, 10),
            reason: '',
            basic_salary: amountToFormValue(contract.basic_salary),
            housing_allowance: amountToFormValue(contract.housing_allowance),
            transport_allowance: amountToFormValue(
                contract.transport_allowance,
            ),
            other_allowances: amountToFormValue(contract.other_allowances),
            supplementary_allowance: amountToFormValue(
                contract.supplementary_allowance,
            ),
            site_allowance: amountToFormValue(contract.site_allowance),
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (): void => {
        form.post(
            storeSalaryRevision.url({
                employee: employeeId,
                employeeContract: contract.id,
            }),
            {
                preserveScroll: true,
                only: reloadOnly,
                onSuccess: () => {
                    setOpen(false);
                    form.reset();
                },
            },
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
                    {canManage ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg"
                            onClick={openDialog}
                        >
                            <Plus className="mr-1 size-3.5" />
                            Add revision
                        </Button>
                    ) : null}
                </div>
            ) : canManage ? (
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 rounded-lg"
                        onClick={openDialog}
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
                                <th className="px-3 py-2 font-medium">
                                    Reason
                                </th>
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
                                        {formatIsoDateDisplay(
                                            revision.effective_from,
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="space-y-0.5">
                                            {revision.lines.map((line) => (
                                                <div key={line.component_code}>
                                                    {line.component_name}:{' '}
                                                    {formatMoney(line.amount)}
                                                </div>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {revision.reason || '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add salary revision</DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Applies immediately and is used by payroll from the
                            effective date.
                        </p>
                    </DialogHeader>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="revision_effective_from">
                                Effective from
                            </Label>
                            <Input
                                id="revision_effective_from"
                                type="date"
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
                            {form.processing ? 'Saving…' : 'Apply revision'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
