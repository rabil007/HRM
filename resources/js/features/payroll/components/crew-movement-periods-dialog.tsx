import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { storeTimesheet } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import UpdateCrewTimesheetSegmentsController from '@/actions/App/Http/Controllers/Payroll/UpdateCrewTimesheetSegmentsController';
import InputError from '@/components/input-error';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDisplayDate } from '@/lib/format-date';
import type {
    CrewPayrollRow,
    CrewTimesheetSegment,
    MovementMasterOptions,
    PayrollPeriod,
} from '../types';

const PAY_CATEGORIES = [
    { value: 'sign_on_standby', label: 'Sign-On Standby' },
    { value: 'onsite', label: 'Onsite' },
    { value: 'sign_off_standby', label: 'Sign-Off Standby' },
] as const;

type DraftSegment = {
    key: string;
    pay_category: string;
    vessel_id: number | null;
    client_id: number | null;
    rank_id: number | null;
    from_date: string;
    to_date: string;
    remarks: string;
};

function inclusiveDays(from: string, to: string): number | null {
    if (!from || !to || to < from) {
        return null;
    }

    const start = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);
    const diff = Math.round((end.getTime() - start.getTime()) / 86_400_000);

    return diff + 1;
}

function segmentFromTimesheet(
    timesheet: CrewPayrollRow['timesheet'],
): DraftSegment[] {
    const existing = timesheet?.segments ?? [];

    if (existing.length > 0) {
        return existing.map((segment, index) => ({
            key: `existing-${segment.id}-${index}`,
            pay_category: segment.pay_category ?? 'onsite',
            vessel_id: segment.vessel_id,
            client_id: segment.client_id,
            rank_id: segment.rank_id,
            from_date: segment.from_date ?? '',
            to_date: segment.to_date ?? '',
            remarks: segment.remarks ?? '',
        }));
    }

    const drafts: DraftSegment[] = [];

    const maybePush = (
        category: string,
        from: string | null | undefined,
        to: string | null | undefined,
    ) => {
        if (!from && !to) {
            return;
        }

        drafts.push({
            key: `legacy-${category}`,
            pay_category: category,
            vessel_id: null,
            client_id: null,
            rank_id: null,
            from_date: from ?? '',
            to_date: to ?? '',
            remarks: '',
        });
    };

    maybePush(
        'sign_on_standby',
        timesheet?.sign_on_standby_from,
        timesheet?.sign_on_standby_to,
    );
    maybePush('onsite', timesheet?.onsite_from, timesheet?.onsite_to);
    maybePush(
        'sign_off_standby',
        timesheet?.sign_off_standby_from,
        timesheet?.sign_off_standby_to,
    );

    if (drafts.length === 0) {
        drafts.push({
            key: `new-${Date.now()}`,
            pay_category: 'onsite',
            vessel_id: null,
            client_id: null,
            rank_id: null,
            from_date: '',
            to_date: '',
            remarks: '',
        });
    }

    return drafts;
}

function ReadOnlySegmentTable({
    segments,
}: {
    segments: CrewTimesheetSegment[];
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="min-w-full text-sm">
                <thead className="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase">
                    <tr>
                        <th className="px-3 py-2">Assignment</th>
                        <th className="px-3 py-2">Vessel</th>
                        <th className="px-3 py-2">Client / project</th>
                        <th className="px-3 py-2">Category</th>
                        <th className="px-3 py-2">From</th>
                        <th className="px-3 py-2">To</th>
                        <th className="px-3 py-2">Days</th>
                        <th className="px-3 py-2">Source</th>
                        <th className="px-3 py-2">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    {segments.map((segment) => (
                        <tr key={segment.id} className="border-t">
                            <td className="px-3 py-2 font-mono text-xs">
                                {segment.assignment_no ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {segment.vessel_name ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {segment.client_name ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {segment.pay_category_label ??
                                    segment.pay_category ??
                                    '—'}
                            </td>
                            <td className="px-3 py-2 font-mono text-xs">
                                {formatDisplayDate(segment.from_date)}
                            </td>
                            <td className="px-3 py-2 font-mono text-xs">
                                {formatDisplayDate(segment.to_date)}
                            </td>
                            <td className="px-3 py-2 tabular-nums">
                                {segment.days ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {segment.source_label ?? segment.source ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-muted-foreground">
                                {segment.remarks ?? '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function CrewMovementPeriodsDialog({
    open,
    onOpenChange,
    period,
    row,
    masterOptions,
    canEdit,
    onBeforeSave,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    period: PayrollPeriod;
    row: CrewPayrollRow | null;
    masterOptions: MovementMasterOptions;
    canEdit: boolean;
    onBeforeSave?: () => Promise<void> | void;
    onSaved?: () => void;
}) {
    if (!row) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <CrewMovementPeriodsDialogBody
                key={`${row.employee.id}-${row.timesheet?.id ?? 'new'}-${open ? 'open' : 'closed'}`}
                period={period}
                row={row}
                masterOptions={masterOptions}
                canEdit={canEdit}
                onOpenChange={onOpenChange}
                onBeforeSave={onBeforeSave}
                onSaved={onSaved}
            />
        </Dialog>
    );
}

function CrewMovementPeriodsDialogBody({
    period,
    row,
    masterOptions,
    canEdit,
    onOpenChange,
    onBeforeSave,
    onSaved,
}: {
    period: PayrollPeriod;
    row: CrewPayrollRow;
    masterOptions: MovementMasterOptions;
    canEdit: boolean;
    onOpenChange: (open: boolean) => void;
    onBeforeSave?: () => Promise<void> | void;
    onSaved?: () => void;
}) {
    const timesheet = row.timesheet ?? null;
    const isLocked = timesheet?.is_operationally_locked === true;
    const editable = canEdit && !isLocked;
    const [segments, setSegments] = useState<DraftSegment[]>(() =>
        segmentFromTimesheet(timesheet),
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const periodHint = useMemo(() => {
        if (!period.start_date || !period.end_date) {
            return null;
        }

        return `${formatDisplayDate(period.start_date)} → ${formatDisplayDate(period.end_date)}`;
    }, [period.end_date, period.start_date]);

    const updateSegment = (
        key: string,
        field: keyof DraftSegment,
        value: string | number | null,
    ) => {
        setSegments((previous) =>
            previous.map((segment) =>
                segment.key === key ? { ...segment, [field]: value } : segment,
            ),
        );
    };

    const addSegment = () => {
        setSegments((previous) => [
            ...previous,
            {
                key: `new-${Date.now()}-${previous.length}`,
                pay_category: 'onsite',
                vessel_id: null,
                client_id: null,
                rank_id: null,
                from_date: '',
                to_date: '',
                remarks: '',
            },
        ]);
    };

    const removeSegment = (key: string) => {
        setSegments((previous) =>
            previous.length <= 1
                ? previous
                : previous.filter((segment) => segment.key !== key),
        );
    };

    const save = async (): Promise<void> => {
        setProcessing(true);
        setErrors({});

        try {
            await onBeforeSave?.();
        } catch {
            setProcessing(false);

            return;
        }

        const segmentPayload = segments.map((segment) => ({
            pay_category: segment.pay_category,
            vessel_id: segment.vessel_id,
            client_id: segment.client_id,
            rank_id: segment.rank_id,
            from_date: segment.from_date || null,
            to_date: segment.to_date || null,
            remarks: segment.remarks || null,
        }));

        const onError = (pageErrors: Record<string, string>): void => {
            const next: Record<string, string> = {};

            Object.entries(pageErrors).forEach(([key, message]) => {
                next[key] = String(message);
            });

            setErrors(next);
        };

        if (timesheet?.id) {
            router.put(
                UpdateCrewTimesheetSegmentsController.url({
                    payrollPeriod: period.id,
                    timesheet: timesheet.id,
                }),
                {
                    segments: segmentPayload,
                },
                {
                    preserveScroll: true,
                    only: ['rows'],
                    onFinish: () => setProcessing(false),
                    onSuccess: () => {
                        onSaved?.();
                        onOpenChange(false);
                    },
                    onError,
                },
            );

            return;
        }

        router.post(
            storeTimesheet.url(period.id),
            {
                period_id: period.id,
                employee_id: row.employee.id,
                unpaid_leave_days: timesheet?.unpaid_leave_days ?? null,
                overtime_hours: timesheet?.overtime_hours ?? 0,
                additional_amount: timesheet?.additional_amount ?? 0,
                deduction_amount: timesheet?.deduction_amount ?? 0,
                remarks: timesheet?.remarks ?? null,
                segments: segmentPayload,
            },
            {
                preserveScroll: true,
                only: ['rows'],
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    onSaved?.();
                    onOpenChange(false);
                },
                onError,
            },
        );
    };

    return (
        <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden glass-card p-0 sm:max-w-4xl">
            <DialogHeader className="shrink-0 space-y-1.5 border-b border-border/60 px-6 py-4 text-left">
                <DialogTitle>Movement Periods</DialogTitle>
                <DialogDescription>
                    {[row.employee.name, row.employee.employee_no]
                        .filter(Boolean)
                        .join(' · ')}
                    {periodHint ? ` · Period ${periodHint}` : ''}
                </DialogDescription>
            </DialogHeader>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                {!editable ? (
                    <ReadOnlySegmentTable
                        segments={timesheet?.segments ?? []}
                    />
                ) : (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Add separate operational periods for this employee.
                            Overtime, unpaid leave, salary inputs, additions and
                            deductions stay once per employee on the payroll
                            board.
                        </p>

                        {segments.map((segment, index) => {
                            const days = inclusiveDays(
                                segment.from_date,
                                segment.to_date,
                            );

                            return (
                                <div
                                    key={segment.key}
                                    className="space-y-3 rounded-xl border border-border/70 bg-muted/10 p-4"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-sm font-medium">
                                            Movement period {index + 1}
                                        </p>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={segments.length <= 1}
                                            onClick={() =>
                                                removeSegment(segment.key)
                                            }
                                            aria-label={`Remove movement period ${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                            Remove
                                        </Button>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Pay category</Label>
                                            <Select
                                                value={segment.pay_category}
                                                onValueChange={(value) =>
                                                    updateSegment(
                                                        segment.key,
                                                        'pay_category',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select category..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {PAY_CATEGORIES.map(
                                                        (category) => (
                                                            <SelectItem
                                                                key={
                                                                    category.value
                                                                }
                                                                value={
                                                                    category.value
                                                                }
                                                            >
                                                                {category.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors[
                                                        `segments.${index}.pay_category`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Days</Label>
                                            <Input
                                                value={
                                                    days === null
                                                        ? ''
                                                        : String(days)
                                                }
                                                readOnly
                                                className="tabular-nums"
                                                aria-label={`Calculated days for movement period ${index + 1}`}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Vessel</Label>
                                            <Select
                                                value={
                                                    segment.vessel_id?.toString() ??
                                                    'none'
                                                }
                                                onValueChange={(value) =>
                                                    updateSegment(
                                                        segment.key,
                                                        'vessel_id',
                                                        value === 'none'
                                                            ? null
                                                            : Number(value),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Optional vessel..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">
                                                        None
                                                    </SelectItem>
                                                    {masterOptions.vessels.map(
                                                        (vessel) => (
                                                            <SelectItem
                                                                key={vessel.id}
                                                                value={vessel.id.toString()}
                                                            >
                                                                {vessel.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors[
                                                        `segments.${index}.vessel_id`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Client / project</Label>
                                            <Select
                                                value={
                                                    segment.client_id?.toString() ??
                                                    'none'
                                                }
                                                onValueChange={(value) =>
                                                    updateSegment(
                                                        segment.key,
                                                        'client_id',
                                                        value === 'none'
                                                            ? null
                                                            : Number(value),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Optional client..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">
                                                        None
                                                    </SelectItem>
                                                    {masterOptions.clients.map(
                                                        (client) => (
                                                            <SelectItem
                                                                key={client.id}
                                                                value={client.id.toString()}
                                                            >
                                                                {client.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors[
                                                        `segments.${index}.client_id`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Rank</Label>
                                            <Select
                                                value={
                                                    segment.rank_id?.toString() ??
                                                    'none'
                                                }
                                                onValueChange={(value) =>
                                                    updateSegment(
                                                        segment.key,
                                                        'rank_id',
                                                        value === 'none'
                                                            ? null
                                                            : Number(value),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Optional rank..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">
                                                        None
                                                    </SelectItem>
                                                    {masterOptions.ranks.map(
                                                        (rank) => (
                                                            <SelectItem
                                                                key={rank.id}
                                                                value={rank.id.toString()}
                                                            >
                                                                {rank.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors[
                                                        `segments.${index}.rank_id`
                                                    ]
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>From</Label>
                                            <Input
                                                type="date"
                                                value={segment.from_date}
                                                min={
                                                    period.start_date ??
                                                    undefined
                                                }
                                                max={
                                                    period.end_date ?? undefined
                                                }
                                                onChange={(event) =>
                                                    updateSegment(
                                                        segment.key,
                                                        'from_date',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `segments.${index}.from_date`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>To</Label>
                                            <Input
                                                type="date"
                                                value={segment.to_date}
                                                min={
                                                    segment.from_date ||
                                                    period.start_date ||
                                                    undefined
                                                }
                                                max={
                                                    period.end_date ?? undefined
                                                }
                                                onChange={(event) =>
                                                    updateSegment(
                                                        segment.key,
                                                        'to_date',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `segments.${index}.to_date`
                                                    ]
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Remarks</Label>
                                        <Textarea
                                            value={segment.remarks}
                                            rows={2}
                                            onChange={(event) =>
                                                updateSegment(
                                                    segment.key,
                                                    'remarks',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `segments.${index}.remarks`
                                                ]
                                            }
                                        />
                                    </div>
                                </div>
                            );
                        })}

                        <Button
                            type="button"
                            variant="outline"
                            onClick={addSegment}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Add Movement Period
                        </Button>

                        <InputError message={errors.segments} />
                        <InputError message={errors.employee_id} />
                    </div>
                )}
            </div>

            <DialogFooter className="shrink-0 border-t border-border/60 px-6 py-4 sm:justify-end">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onOpenChange(false)}
                    disabled={processing}
                >
                    {editable ? 'Cancel' : 'Close'}
                </Button>
                {editable ? (
                    <Button
                        type="button"
                        onClick={() => {
                            void save();
                        }}
                        disabled={processing}
                    >
                        {processing ? <Spinner className="mr-2" /> : null}
                        Save movement periods
                    </Button>
                ) : null}
            </DialogFooter>
        </DialogContent>
    );
}
