import { router } from '@inertiajs/react';
import {
    Anchor,
    BriefcaseBusiness,
    CalendarDays,
    CalendarRange,
    ChevronDown,
    ChevronUp,
    Info,
    Lock,
    Plus,
    Ship,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
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
import { cn } from '@/lib/utils';
import {
    buildAssignmentSummaryFields,
    categoryGroupCategories,
    createEmptyMovementPeriodDraft,
    defaultCategoryForGroup,
    hiddenGroupSegmentDrafts,
    inclusiveMovementDays,
    isAssignmentEditorOpen,
    resolveDefaultAssignment,
    segmentDraftsFromTimesheet,
    splitMovementRangeAcrossPeriod,
    toggleAssignmentEditor,
} from '../lib/crew-movement-period-drafts';
import type {
    MovementCategoryGroup,
    MovementPeriodDraftSegment,
} from '../lib/crew-movement-period-drafts';
import type {
    CrewPayrollRow,
    CrewTimesheetSegment,
    MovementMasterOptions,
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
                    No movement periods recorded
                </p>
                <p className="text-xs text-muted-foreground/70">
                    Movement periods for this employee haven't been entered yet.
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
                        <th className="px-3 py-2 font-semibold">Vessel</th>
                        <th className="px-3 py-2 font-semibold">
                            Client / Project
                        </th>
                        <th className="px-3 py-2 font-semibold">Rank</th>
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
                            <td className="px-3 py-2.5 text-muted-foreground">
                                {segment.vessel_name ?? (
                                    <span className="italic">Not assigned</span>
                                )}
                            </td>
                            <td className="px-3 py-2.5 text-muted-foreground">
                                {segment.client_name ?? (
                                    <span className="italic">Not assigned</span>
                                )}
                            </td>
                            <td className="px-3 py-2.5 text-muted-foreground">
                                {segment.rank_name ?? (
                                    <span className="italic">Not assigned</span>
                                )}
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

function AssignmentSummary({
    segment,
    masterOptions,
    editing,
    onToggle,
    segmentIndex,
}: {
    segment: MovementPeriodDraftSegment;
    masterOptions: MovementMasterOptions;
    editing: boolean;
    onToggle: () => void;
    segmentIndex: number;
}) {
    const fields = buildAssignmentSummaryFields(segment, masterOptions);

    return (
        <div className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <BriefcaseBusiness
                        className="h-4 w-4 shrink-0"
                        aria-hidden
                    />
                    <span>Current Assignment</span>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onToggle}
                    aria-expanded={editing}
                    aria-controls={`assignment-fields-${segment.key}`}
                >
                    {editing ? (
                        <ChevronUp className="h-4 w-4" aria-hidden />
                    ) : (
                        <ChevronDown className="h-4 w-4" aria-hidden />
                    )}
                    {editing ? 'Hide Assignment' : 'Change Assignment'}
                </Button>
            </div>

            {!editing ? (
                <dl className="grid gap-2 sm:grid-cols-3">
                    {fields.map((field) => (
                        <div key={field.label} className="space-y-0.5">
                            <dt className="text-xs text-muted-foreground">
                                {field.label}
                            </dt>
                            <dd
                                className={cn(
                                    'text-sm',
                                    field.assigned
                                        ? 'text-foreground'
                                        : 'text-muted-foreground italic',
                                )}
                            >
                                {field.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="text-xs text-muted-foreground">
                    Update vessel, client, or rank for movement period{' '}
                    {segmentIndex + 1}. Clear a value with None.
                </p>
            )}
        </div>
    );
}

function OptionalMasterSelect({
    label,
    value,
    options,
    onChange,
    error,
    icon,
}: {
    label: string;
    value: number | null;
    options: MovementMasterOptions['vessels'];
    onChange: (value: number | null) => void;
    error?: string;
    icon: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <Label className="flex items-center gap-1.5">
                {icon}
                {label}
            </Label>
            <Select
                value={value?.toString() ?? 'none'}
                onValueChange={(next) =>
                    onChange(next === 'none' ? null : Number(next))
                }
            >
                <SelectTrigger>
                    <SelectValue
                        placeholder={`Optional ${label.toLowerCase()}...`}
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">None</SelectItem>
                    {options.map((option) => (
                        <SelectItem
                            key={option.id}
                            value={option.id.toString()}
                        >
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

export function CrewMovementPeriodsDialog({
    open,
    onOpenChange,
    period,
    row,
    categoryGroup,
    masterOptions,
    canEdit,
    onBeforeSave,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    period: PayrollPeriod;
    row: CrewPayrollRow | null;
    categoryGroup: MovementCategoryGroup;
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
                key={`${row.employee.id}-${categoryGroup}-${open ? 'open' : 'closed'}`}
                period={period}
                row={row}
                categoryGroup={categoryGroup}
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
    categoryGroup,
    masterOptions,
    canEdit,
    onOpenChange,
    onBeforeSave,
    onSaved,
}: {
    period: PayrollPeriod;
    row: CrewPayrollRow;
    categoryGroup: MovementCategoryGroup;
    masterOptions: MovementMasterOptions;
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
    const [assignmentEditorKeys, setAssignmentEditorKeys] = useState<
        Set<string>
    >(() => new Set());
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
        setSegments((previous) => {
            const assignment = resolveDefaultAssignment(previous, timesheet);

            return [
                ...previous,
                createEmptyMovementPeriodDraft(
                    `new-${Date.now()}-${previous.length}`,
                    assignment,
                    defaultCategoryForGroup(categoryGroup),
                ),
            ];
        });
    };

    const removeSegment = (key: string) => {
        setSegments((previous) =>
            previous.filter((segment) => segment.key !== key),
        );
        setAssignmentEditorKeys((previous) => {
            const next = new Set(previous);
            next.delete(key);

            return next;
        });
    };

    const save = async (): Promise<void> => {
        if (hasDateAfterPeriodEnd) {
            setErrors({
                segments:
                    'Movement dates after the payroll period end are not allowed.',
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
                        : 'Pending financial changes could not be saved. Movement periods were not updated.',
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

        const collapseAssignmentEditors = (): void => {
            setAssignmentEditorKeys(new Set());
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
                        collapseAssignmentEditors();
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
                    collapseAssignmentEditors();
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
                            {groupLabel} Periods
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
                                ? 'movement period'
                                : 'movement periods'}
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
                                    Crew Operations data — movement periods are
                                    read-only. Dates and assignment can only be
                                    changed via the Crew Operations workflow.
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
                            Add one row per movement leg. Dates and pay category
                            are required. Vessel, client, and rank are optional
                            — expand{' '}
                            <strong className="font-medium text-foreground/80">
                                Assignment
                            </strong>{' '}
                            to change them.
                        </p>

                        {segments.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border/70 py-10 text-center">
                                <CalendarRange
                                    className="h-8 w-8 text-muted-foreground/40"
                                    aria-hidden
                                />
                                <p className="text-sm font-medium text-muted-foreground">
                                    No movement periods yet
                                </p>
                                <p className="text-xs text-muted-foreground/70">
                                    Click{' '}
                                    <span className="font-medium">
                                        Add Movement Period
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
                            const assignmentOpen = isAssignmentEditorOpen(
                                assignmentEditorKeys,
                                segment.key,
                            );

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
                                            aria-label={`Remove movement period ${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                            Remove
                                        </Button>
                                    </div>

                                    <section
                                        className="space-y-3"
                                        aria-labelledby={`movement-details-${segment.key}`}
                                    >
                                        <h3
                                            id={`movement-details-${segment.key}`}
                                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                        >
                                            1. Movement Details
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
                                                    aria-label={`Calculated days for movement period ${index + 1}`}
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
                                                <Info
                                                    className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300"
                                                    aria-hidden
                                                />
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

                                    <section
                                        className="space-y-3"
                                        aria-labelledby={`assignment-${segment.key}`}
                                    >
                                        <h3
                                            id={`assignment-${segment.key}`}
                                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                        >
                                            2. Assignment
                                        </h3>

                                        <AssignmentSummary
                                            segment={segment}
                                            masterOptions={masterOptions}
                                            editing={assignmentOpen}
                                            segmentIndex={index}
                                            onToggle={() =>
                                                setAssignmentEditorKeys(
                                                    (previous) =>
                                                        toggleAssignmentEditor(
                                                            previous,
                                                            segment.key,
                                                            !assignmentOpen,
                                                        ),
                                                )
                                            }
                                        />

                                        {assignmentOpen ? (
                                            <div
                                                id={`assignment-fields-${segment.key}`}
                                                className="grid gap-3 sm:grid-cols-3"
                                            >
                                                <OptionalMasterSelect
                                                    label="Vessel"
                                                    value={segment.vessel_id}
                                                    options={
                                                        masterOptions.vessels
                                                    }
                                                    icon={
                                                        <Ship
                                                            className="h-3.5 w-3.5"
                                                            aria-hidden
                                                        />
                                                    }
                                                    onChange={(value) =>
                                                        updateSegment(
                                                            segment.key,
                                                            'vessel_id',
                                                            value,
                                                        )
                                                    }
                                                    error={
                                                        errors[
                                                            `segments.${index}.vessel_id`
                                                        ]
                                                    }
                                                />
                                                <OptionalMasterSelect
                                                    label="Client"
                                                    value={segment.client_id}
                                                    options={
                                                        masterOptions.clients
                                                    }
                                                    icon={
                                                        <Anchor
                                                            className="h-3.5 w-3.5"
                                                            aria-hidden
                                                        />
                                                    }
                                                    onChange={(value) =>
                                                        updateSegment(
                                                            segment.key,
                                                            'client_id',
                                                            value,
                                                        )
                                                    }
                                                    error={
                                                        errors[
                                                            `segments.${index}.client_id`
                                                        ]
                                                    }
                                                />
                                                <OptionalMasterSelect
                                                    label="Rank"
                                                    value={segment.rank_id}
                                                    options={
                                                        masterOptions.ranks
                                                    }
                                                    icon={
                                                        <UserRound
                                                            className="h-3.5 w-3.5"
                                                            aria-hidden
                                                        />
                                                    }
                                                    onChange={(value) =>
                                                        updateSegment(
                                                            segment.key,
                                                            'rank_id',
                                                            value,
                                                        )
                                                    }
                                                    error={
                                                        errors[
                                                            `segments.${index}.rank_id`
                                                        ]
                                                    }
                                                />
                                            </div>
                                        ) : null}
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
                            Add Movement Period
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
                        Save movement periods
                    </Button>
                ) : null}
            </DialogFooter>
        </DialogContent>
    );
}
