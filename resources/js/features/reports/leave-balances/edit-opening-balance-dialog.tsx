import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import LeaveBalanceReportController from '@/actions/App/Http/Controllers/Organization/LeaveBalanceReportController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { LeaveBalanceReportRow } from './types';

function days(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function decimalInputValue(value: number): string {
    return value.toFixed(2);
}

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    balance: LeaveBalanceReportRow | null;
    companyToday: string;
};

export function EditOpeningBalanceDialog({
    open,
    onOpenChange,
    balance,
    companyToday,
}: Props) {
    const form = useForm({
        opening_used_days: '0.00',
        opening_balance_as_of: companyToday,
        opening_balance_note: '',
    });

    useEffect(() => {
        if (!open || !balance) {
            return;
        }

        form.clearErrors();
        form.setData({
            opening_used_days: decimalInputValue(balance.opening_used_days),
            opening_balance_as_of:
                balance.opening_balance_as_of ?? companyToday,
            opening_balance_note: balance.opening_balance_note ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when dialog opens for a row
    }, [open, balance?.id, companyToday]);

    const preview = useMemo(() => {
        if (!balance) {
            return null;
        }

        const opening = Number.parseFloat(form.data.opening_used_days) || 0;
        const totalUsed = opening + balance.used_days;
        const remaining =
            balance.total_available -
            opening -
            balance.used_days -
            balance.pending_days;

        return {
            opening,
            totalUsed,
            remaining,
            isNegative: remaining < 0,
        };
    }, [balance, form.data.opening_used_days]);

    if (!balance) {
        return null;
    }

    const employeeLabel = balance.employee.employee_no
        ? `${balance.employee.name} · ${balance.employee.employee_no}`
        : balance.employee.name;

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();

        form.patch(LeaveBalanceReportController.updateOpening.url(balance.id), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg glass-card">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>Edit Opening Balance</DialogTitle>
                        <DialogDescription>
                            Record leave already used before OMS-HRM tracking
                            for this year.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4 space-y-4">
                        <div className="rounded-xl border border-border/70 bg-muted/20 px-3 py-3 text-sm">
                            <p className="font-semibold text-foreground">
                                {employeeLabel}
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                {balance.leave_type.name} · {balance.year}
                            </p>
                            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground tabular-nums">
                                <dt>Base entitlement</dt>
                                <dd className="text-right text-foreground">
                                    {days(balance.base_entitlement)}
                                </dd>
                                <dt>Carried</dt>
                                <dd className="text-right text-foreground">
                                    {days(balance.carried_days)}
                                </dd>
                                <dt>HRM used</dt>
                                <dd className="text-right text-foreground">
                                    {days(balance.used_days)}
                                </dd>
                                <dt>Pending</dt>
                                <dd className="text-right text-foreground">
                                    {days(balance.pending_days)}
                                </dd>
                            </dl>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="opening_used_days">
                                Previous used days
                            </Label>
                            <Input
                                id="opening_used_days"
                                type="number"
                                min={0}
                                max={9999.99}
                                step="0.01"
                                value={form.data.opening_used_days}
                                onChange={(event) =>
                                    form.setData(
                                        'opening_used_days',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.opening_used_days ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.opening_used_days}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="opening_balance_as_of">
                                As of date
                            </Label>
                            <Input
                                id="opening_balance_as_of"
                                type="date"
                                max={companyToday}
                                value={form.data.opening_balance_as_of}
                                onChange={(event) =>
                                    form.setData(
                                        'opening_balance_as_of',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.opening_balance_as_of ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.opening_balance_as_of}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="opening_balance_note">Note</Label>
                            <Textarea
                                id="opening_balance_note"
                                rows={3}
                                maxLength={1000}
                                placeholder="Imported from previous HR leave records"
                                value={form.data.opening_balance_note}
                                onChange={(event) =>
                                    form.setData(
                                        'opening_balance_note',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.opening_balance_note ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.opening_balance_note}
                                </p>
                            ) : null}
                        </div>

                        {preview ? (
                            <div
                                className={
                                    preview.isNegative
                                        ? 'rounded-xl border border-rose-500/40 bg-rose-500/10 px-3 py-3 text-xs'
                                        : 'rounded-xl border border-border/70 bg-muted/20 px-3 py-3 text-xs'
                                }
                            >
                                <p className="mb-2 font-semibold tracking-wide text-muted-foreground uppercase">
                                    After update
                                </p>
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 tabular-nums">
                                    <dt>Available</dt>
                                    <dd className="text-right">
                                        {days(balance.total_available)}
                                    </dd>
                                    <dt>Total used</dt>
                                    <dd className="text-right">
                                        {days(preview.totalUsed)}
                                    </dd>
                                    <dt>Pending</dt>
                                    <dd className="text-right">
                                        {days(balance.pending_days)}
                                    </dd>
                                    <dt>Remaining</dt>
                                    <dd
                                        className={
                                            preview.isNegative
                                                ? 'text-right font-semibold text-rose-600 dark:text-rose-400'
                                                : 'text-right font-semibold'
                                        }
                                    >
                                        {days(preview.remaining)}
                                    </dd>
                                </dl>
                                {preview.isNegative ? (
                                    <p className="mt-2 text-rose-700 dark:text-rose-300">
                                        Remaining would be below zero. Confirm
                                        this is intentional before saving.
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>

                    <DialogFooter className="mt-6">
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-xl"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            className="rounded-xl"
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
