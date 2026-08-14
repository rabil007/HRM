import { router } from '@inertiajs/react';
import { CalendarDays, CalendarRange, Lock, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
import {
    categoryGroupCategories,
    createEmptyMovementPeriodDraft,
    defaultCategoryForGroup,
    hiddenGroupSegmentDrafts,
    inclusiveMovementDays,
    segmentDraftsFromTimesheet,
    splitMovementRangeAcrossPeriod,
} from '../lib/crew-movement-period-drafts';
import type {
    MovementCategoryGroup,
    MovementPeriodDraftSegment,
} from '../lib/crew-movement-period-drafts';
import type {
    CrewPayrollRow,
    CrewTimesheetSegment,
    PayrollPeriod,
} from '../types';

const ALL_PAY_CATEGORIES = [
    { value: 'sign_on_standby', label: 'Sign-On Standby' },
    { value: 'onsite', label: 'Onsite' },
    { value: 'sign_off_standby', label: 'Sign-Off Standby' },
] as const;

function ReadOnlySegmentTable({
    segments,
}: {
    segments: CrewTimesheetSegment[];
}) {
    if (segments.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border/60 py-10 text-center">
                <CalendarRange
                    className="h-8 w-8 text-muted-foreground/40"
                    aria-hidden
                />
                <p className="text-sm font-medium text-muted-foreground">
                    No timesheet periods recorded
                </p>
                <p className="text-xs text-muted-foreground/70">
                    Timesheet periods for this employee haven't been entered
                    yet.
                </p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="min-w-full text-sm">
                <thead className="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase">
                    <tr>
                        <th className="px-3 py-2 font-semibold">Category</th>
                        <th className="px-3 py-2 font-semibold">From</th>
                        <th className="px-3 py-2 font-semibold">To</th>
                        <th className="px-3 py-2 font-semibold">Days</th>
                        <th className="px-3 py-2 font-semibold">Assignment</th>
                        <th className="px-3 py-2 font-semibold">Remarks</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border/50">
                    {segments.map((segment) => (
                        <tr
                            key={segment.id}
                            className="transition-colors hover:bg-muted/20"
                        >
                            <td className="px-3 py-2.5">
                                <span className="rounded-md bg-muted/60 px-2 py-0.5 text-xs font-medium">
                                    {segment.pay_category_label ??
                                        segment.pay_category ??
                                        '—'}
                                </span>
                            </td>
                            <td className="px-3 py-2.5 font-mono text-xs">
                                {formatDisplayDate(segment.from_date)}
                            </td>
                            <td className="px-3 py-2.5 font-mono text-xs">
                                {formatDisplayDate(segment.to_date)}
                            </td>
                            <td className="px-3 py-2.5 font-semibold tabular-nums">
                                {segment.days ?? '—'}
                            </td>
                            <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">
                                {segment.assignment_no ?? (
                                    <span className="font-sans italic">
                                        Not assigned
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2.5 text-muted-foreground">
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
    categoryGroup,
    canEdit,
    onBeforeSave,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    period: PayrollPeriod;
    row: CrewPayrollRow | null;
    categoryGroup: MovementCategoryGroup;
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
                key={`${row.employee.id}-${categoryGroup}-${open ? 'open' : 'closed'}`}
                period={period}
                row={row}
                categoryGroup={categoryGroup}
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
    categoryGroup,
    canEdit,
    onOpenChange,
    onBeforeSave,
    onSaved,
}: {
    period: PayrollPeriod;
    row: CrewPayrollRow;
    categoryGroup: MovementCategoryGroup;
    canEdit: boolean;
    onOpenChange: (open: boolean) => void;
    onBeforeSave?: () => Promise<void> | void;
    onSaved?: () => void;
}) {
    const timesheet = row.timesheet ?? null;
    const rowRef = useRef(row);

    useEffect(() => {
        rowRef.current = row;
    }, [row]);

    const visibleCategories = categoryGroupCategories(categoryGroup);
    const payCategories = ALL_PAY_CATEGORIES.filter((c) =>
        visibleCategories.includes(c.value),
    );
    const groupLabel =
        categoryGroup === 'standby' ? 'Sign-On / Sign-Off Standby' : 'Onsite';

    const isLocked = timesheet?.is_operationally_locked === true;
    const editable = canEdit && !isLocked;
    const [segments, setSegments] = useState<MovementPeriodDraftSegment[]>(() =>
        segmentDraftsFromTimesheet(timesheet, categoryGroup),
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const periodHint = useMemo(() => {
        if (!period.start_date || !period.end_date) {
            return null;
        }

        return `${formatDisplayDate(period.start_date)} → ${formatDisplayDate(period.end_date)}`;
    }, [period.end_date, period.start_date]);

    const totalDays = useMemo(
        () =>
            segments.reduce((sum, seg) => {
                const days = inclusiveMovementDays(seg.from_date, seg.to_date);

                return sum + (days ?? 0);
            }, 0),
        [segments],
    );

    const hasDateAfterPeriodEnd = useMemo(() => {
        if (!period.end_date) {
            return false;
        }

        return segments.some((segment) => {
            const split = splitMovementRangeAcrossPeriod(
                segment.from_date,
                segment.to_date,
                period.start_date ?? period.end_date ?? '',
                period.end_date,
            );

            return (
                split?.exceedsPeriodEnd === true ||
                (segment.from_date !== '' &&
                    segment.from_date > period.end_date!) ||
                (segment.to_date !== '' && segment.to_date > period.end_date!)
            );
        });
    }, [period.end_date, period.start_date, segments]);

    const updateSegment = (
        key: string,
        field: keyof MovementPeriodDraftSegment,
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
            createEmptyMovementPeriodDraft(
                `new-${Date.now()}-${previous.length}`,
                defaultCategoryForGroup(categoryGroup),
            ),
        ]);
    };

    const removeSegment = (key: string) => {
        setSegments((previous) =>
            previous.filter((segment) => segment.key !== key),
        );
    };

    const save = async (): Promise<void> => {
        if (hasDateAfterPeriodEnd) {
            setErrors({
                segments:
                    'Timesheet dates after the payroll period end are not allowed.',
            });

            return;
        }

        setProcessing(true);
        setErrors({});

        try {
            await onBeforeSave?.();
        } catch (error) {
            setProcessing(false);
            setErrors({
                financials:
                    error instanceof Error
                        ? error.message
                        : 'Pending financial changes could not be saved. Timesheet periods were not updated.',
            });

            return;
        }

        // Preserve segments that belong to the other category group so they
        // are not lost when only one group is being edited.
        const latestRow = rowRef.current;
        const hiddenSegments = hiddenGroupSegmentDrafts(
            latestRow.timesheet,
            categoryGroup,
        );
        const allSegments = [...hiddenSegments, ...segments];

        const segmentPayload = allSegments.map((segment) => ({
            pay_category: segment.pay_category,
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

        const latestTimesheet = rowRef.current.timesheet ?? timesheet;

        if (latestTimesheet?.id) {
            router.put(
                UpdateCrewTimesheetSegmentsController.url({
                    payrollPeriod: period.id,
                    timesheet: latestTimesheet.id,
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
        <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden glass-card p-0 sm:max-w-3xl">
            <DialogHeader className="shrink-0 space-y-0 border-b border-border/60 px-6 py-4 text-left">
                <div className="flex items-start justify-between gap-3">
                    <div className="space-y-1">
                        <DialogTitle className="flex items-center gap-2">
                            <CalendarRange
                                className="h-5 w-5 text-muted-foreground"
                                aria-hidden
                            />
                            {groupLabel} Timesheet Periods
                        </DialogTitle>
                        <DialogDescription className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                            <span className="font-medium text-foreground/80">
                                {row.employee.name}
                            </span>
                            {row.employee.employee_no ? (
                                <span className="font-mono text-muted-foreground">
                                    {row.employee.employee_no}
                                </span>
                            ) : null}
                            {periodHint ? (
                                <>
                                    <span className="text-border">·</span>
                                    <span className="flex items-center gap-1 text-muted-foreground">
                                        <CalendarDays
                                            className="h-3 w-3"
                                            aria-hidden
                                        />
                                        {periodHint}
                                    </span>
                                </>
                            ) : null}
                        </DialogDescription>
                    </div>
                    {isLocked ? (
                        <span className="mt-0.5 flex shrink-0 items-center gap-1 rounded-md border border-border/60 bg-muted/40 px-2 py-1 text-xs text-muted-foreground">
                            <Lock className="h-3 w-3" aria-hidden />
                            Crew Ops — read-only
                        </span>
                    ) : null}
                </div>

                {editable && segments.length > 0 ? (
                    <div className="mt-3 flex flex-wrap items-center gap-4 rounded-lg border border-border/50 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1.5">
                            <span className="font-semibold text-foreground tabular-nums">
                                {segments.length}
                            </span>
                            {segments.length === 1
                                ? 'timesheet period'
                                : 'timesheet periods'}
                        </span>
                        {totalDays > 0 ? (
                            <span className="flex items-center gap-1.5">
                                <span className="font-semibold text-foreground tabular-nums">
                                    {totalDays}
                                </span>
                                total days
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </DialogHeader>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                {!editable ? (
                    <>
                        {isLocked ? (
                            <div className="flex items-start gap-2.5 rounded-lg border border-border/60 bg-muted/20 px-3.5 py-3 text-sm text-muted-foreground">
                                <Lock
                                    className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/60"
                                    aria-hidden
                                />
                                <p>
                                    Crew Operations data — timesheet periods are
                                    read-only. Dates and assignment are managed
                                    via the Crew Operations workflow.
                                </p>
                            </div>
                        ) : null}
                        <ReadOnlySegmentTable
                            segments={(timesheet?.segments ?? []).filter((s) =>
                                visibleCategories.includes(
                                    s.pay_category ?? '',
                                ),
                            )}
                        />
                    </>
                ) : (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Add one row for each payable timesheet period. Pay
                            category and dates are required.
                        </p>

                        {segments.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border/70 py-10 text-center">
                                <CalendarRange
                                    className="h-8 w-8 text-muted-foreground/40"
                                    aria-hidden
                                />
                                <p className="text-sm font-medium text-muted-foreground">
                                    No timesheet periods yet
                                </p>
                                <p className="text-xs text-muted-foreground/70">
                                    Click{' '}
                                    <span className="font-medium">
                                        Add Timesheet Period
                                    </span>{' '}
                                    below to get started.
                                </p>
                            </div>
                        ) : null}

                        {segments.map((segment, index) => {
                            const days = inclusiveMovementDays(
                                segment.from_date,
                                segment.to_date,
                            );
                            const rangeSplit =
                                period.start_date && period.end_date
                                    ? splitMovementRangeAcrossPeriod(
                                          segment.from_date,
                                          segment.to_date,
                                          period.start_date,
                                          period.end_date,
                                      )
                                    : null;
                            const hasPriorArrears =
                                rangeSplit !== null && rangeSplit.priorDays > 0;
                            const exceedsPeriodEnd =
                                rangeSplit?.exceedsPeriodEnd === true ||
                                (Boolean(period.end_date) &&
                                    ((segment.from_date !== '' &&
                                        segment.from_date >
                                            (period.end_date ?? '')) ||
                                        (segment.to_date !== '' &&
                                            segment.to_date >
                                                (period.end_date ?? ''))));

                            return (
                                <div
                                    key={segment.key}
                                    className="space-y-4 rounded-xl border border-border/70 bg-card/40 p-4"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <p className="text-sm font-semibold">
                                                Period {index + 1}
                                            </p>
                                            {days !== null ? (
                                                <span className="rounded-md bg-blue-500/10 px-2 py-0.5 text-xs font-semibold text-blue-700 tabular-nums dark:text-blue-300">
                                                    {days}{' '}
                                                    {days === 1
                                                        ? 'day'
                                                        : 'days'}
                                                </span>
                                            ) : null}
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                removeSegment(segment.key)
                                            }
                                            aria-label={`Remove timesheet period ${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                            Remove
                                        </Button>
                                    </div>

                                    <section
                                        className="space-y-3"
                                        aria-labelledby={`timesheet-details-${segment.key}`}
                                    >
                                        <h3
                                            id={`timesheet-details-${segment.key}`}
                                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                        >
                                            TIMESHEET DETAILS
                                        </h3>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`pay-category-${segment.key}`}
                                                >
                                                    Pay category
                                                </Label>
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
                                                    <SelectTrigger
                                                        id={`pay-category-${segment.key}`}
                                                    >
                                                        <SelectValue placeholder="Select category..." />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {payCategories.map(
                                                            (category) => (
                                                                <SelectItem
                                                                    key={
                                                                        category.value
                                                                    }
                                                                    value={
                                                                        category.value
                                                                    }
                                                                >
                                                                    {
                                                                        category.label
                                                                    }
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
                                                <Label
                                                    htmlFor={`days-${segment.key}`}
                                                >
                                                    Days
                                                </Label>
                                                <Input
                                                    id={`days-${segment.key}`}
                                                    value={
                                                        days === null
                                                            ? ''
                                                            : String(days)
                                                    }
                                                    readOnly
                                                    className="tabular-nums"
                                                    aria-label={`Calculated days for timesheet period ${index + 1}`}
                                                />
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`from-${segment.key}`}
                                                >
                                                    From date
                                                </Label>
                                                <Input
                                                    id={`from-${segment.key}`}
                                                    type="date"
                                                    value={segment.from_date}
                                                    max={
                                                        period.end_date ??
                                                        undefined
                                                    }
                                                    aria-invalid={
                                                        exceedsPeriodEnd ||
                                                        undefined
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
                                                <Label
                                                    htmlFor={`to-${segment.key}`}
                                                >
                                                    To date
                                                </Label>
                                                <Input
                                                    id={`to-${segment.key}`}
                                                    type="date"
                                                    value={segment.to_date}
                                                    min={
                                                        segment.from_date ||
                                                        undefined
                                                    }
                                                    max={
                                                        period.end_date ??
                                                        undefined
                                                    }
                                                    aria-invalid={
                                                        exceedsPeriodEnd ||
                                                        undefined
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

                                        {exceedsPeriodEnd ? (
                                            <p
                                                role="alert"
                                                className="text-sm text-destructive"
                                            >
                                                Dates after the payroll period
                                                end (
                                                {formatDisplayDate(
                                                    period.end_date,
                                                )}
                                                ) are not allowed.
                                            </p>
                                        ) : null}

                                        {hasPriorArrears &&
                                        !exceedsPeriodEnd ? (
                                            <div
                                                role="status"
                                                className="flex items-start gap-2.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3.5 py-3 text-sm text-amber-900 dark:text-amber-100"
                                            >
                                                <div className="space-y-1">
                                                    <p className="font-medium">
                                                        Days before the period
                                                        start will be treated as
                                                        prior-period arrears.
                                                    </p>
                                                    <p className="text-xs text-amber-800/90 dark:text-amber-200/90">
                                                        <span className="font-semibold tabular-nums">
                                                            {
                                                                rangeSplit.priorDays
                                                            }
                                                        </span>{' '}
                                                        prior-period{' '}
                                                        {rangeSplit.priorDays ===
                                                        1
                                                            ? 'day'
                                                            : 'days'}
                                                        {rangeSplit.currentDays >
                                                        0 ? (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                <span className="font-semibold tabular-nums">
                                                                    {
                                                                        rangeSplit.currentDays
                                                                    }
                                                                </span>{' '}
                                                                current-period{' '}
                                                                {rangeSplit.currentDays ===
                                                                1
                                                                    ? 'day'
                                                                    : 'days'}
                                                            </>
                                                        ) : null}
                                                        {period.start_date ? (
                                                            <>
                                                                {' '}
                                                                (period starts{' '}
                                                                {formatDisplayDate(
                                                                    period.start_date,
                                                                )}
                                                                )
                                                            </>
                                                        ) : null}
                                                    </p>
                                                </div>
                                            </div>
                                        ) : null}

                                        <div className="space-y-2">
                                            <Label
                                                htmlFor={`remarks-${segment.key}`}
                                            >
                                                Remarks
                                            </Label>
                                            <Textarea
                                                id={`remarks-${segment.key}`}
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
                                    </section>
                                </div>
                            );
                        })}

                        <Button
                            type="button"
                            variant="outline"
                            onClick={addSegment}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Add Timesheet Period
                        </Button>

                        <InputError message={errors.financials} />
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
                        disabled={processing || hasDateAfterPeriodEnd}
                    >
                        {processing ? <Spinner className="mr-2" /> : null}
                        Save Timesheet Periods
                    </Button>
                ) : null}
            </DialogFooter>
        </DialogContent>
    );
}
