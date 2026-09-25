import type { InertiaFormProps } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useMemo } from 'react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { assignmentDurationDays } from '../lib/planning-gantt-math';
import type {
    AssignmentFormData,
    GanttBar,
    PlanningOption,
} from '../types';

const fieldInputClass =
    'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

export function AssignCrewSheet({
    open,
    onOpenChange,
    form,
    onSubmit,
    editing,
    relievesEmployeeName,
    vessels,
    ranks,
    ranks,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: InertiaFormProps<AssignmentFormData>;
    onSubmit: () => void;
    editing: GanttBar | null;
    relievesEmployeeName: string;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
}): ReactElement {
    const isEdit = editing !== null;

    const plannedDurationDays =
        form.data.planned_join_date !== '' &&
        form.data.planned_leave_date !== ''
            ? assignmentDurationDays(
                  form.data.planned_join_date,
                  form.data.planned_leave_date,
              )
            : null;

    const handleRankChange = (value: string): void => {
        form.setData({
            ...form.data,
            rank_id: value,
            relieves_crew_assignment_id: '',
        });
    };

    const handleVesselChange = (value: string): void => {
        form.setData({
            ...form.data,
            vessel_id: value,
            relieves_crew_assignment_id: '',
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {isEdit ? 'Edit Plan' : 'Save Plan'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {isEdit
                            ? 'Update the planned assignment on the Gantt board.'
                            : 'Schedule crew on a vessel and rank for the selected dates.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="rounded-xl border border-sky-500/35 bg-sky-500/10 px-4 py-3 text-sm text-sky-900 dark:text-sky-100">
                        <div className="flex gap-3">
                            <Info
                                className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300"
                                aria-hidden
                            />
                            <div className="space-y-1">
                                <p>
                                    This creates a Planning record only. It does
                                    not start mobilisation or place the employee
                                    onboard.
                                </p>
                                <p>
                                    After conversion to Crew Assignments,
                                    operational dates and movements are
                                    controlled from Crew Assignments.
                                </p>
                            </div>
                        </div>
                    </div>

                    {relievesEmployeeName !== '' ? (
                        <div className="rounded-xl border border-sky-500/35 bg-sky-500/10 px-4 py-3 text-sm">
                            <p className="font-semibold text-sky-800 dark:text-sky-300">
                                Relieving summary
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                Relieving:{' '}
                                <span className="font-medium text-foreground">
                                    {relievesEmployeeName}
                                </span>
                            </p>
                            {form.data.planned_join_date !== '' ? (
                                <p className="mt-1 text-muted-foreground">
                                    Suggested relief join:{' '}
                                    <span className="font-medium text-foreground">
                                        {form.data.planned_join_date}
                                    </span>
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="vessel_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Vessel *
                            </Label>
                            <AppSelect
                                value={form.data.vessel_id}
                                onValueChange={handleVesselChange}
                                placeholder="Select vessel"
                                variant="card"
                            >
                                {vessels.map((v) => (
                                    <AppSelectItem
                                        key={v.id}
                                        value={String(v.id)}
                                    >
                                        {v.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.vessel_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.vessel_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="rank_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Rank *
                            </Label>
                            <AppSelect
                                value={form.data.rank_id}
                                onValueChange={handleRankChange}
                                placeholder={
                                    form.data.vessel_id === ''
                                        ? 'Select vessel first'
                                        : 'Select rank'
                                }
                                disabled={form.data.vessel_id === ''}
                                variant="card"
                            >
                                {ranks.map((r) => (
                                    <AppSelectItem
                                        key={r.id}
                                        value={String(r.id)}
                                    >
                                        {r.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.rank_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.rank_id}
                                </div>
                            ) : null}
                        </div>

                        </div>
                    </div>

                    <div className="space-y-5 border-t border-border/60 pt-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="planned_join_date"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Planned join *
                                </Label>
                                <Input
                                    id="planned_join_date"
                                    type="date"
                                    value={form.data.planned_join_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'planned_join_date',
                                            e.target.value,
                                        )
                                    }
                                    className={fieldInputClass}
                                />
                                {form.errors.planned_join_date ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.planned_join_date}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="planned_leave_date"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Planned leave *
                                </Label>
                                <Input
                                    id="planned_leave_date"
                                    type="date"
                                    value={form.data.planned_leave_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'planned_leave_date',
                                            e.target.value,
                                        )
                                    }
                                    className={fieldInputClass}
                                />
                                {form.errors.planned_leave_date ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.planned_leave_date}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        {plannedDurationDays !== null &&
                        plannedDurationDays > 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Planned duration:{' '}
                                <span className="font-medium text-foreground">
                                    {plannedDurationDays}{' '}
                                    {plannedDurationDays === 1 ? 'day' : 'days'}
                                </span>
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-5 border-t border-border/60 pt-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="notes"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Notes{' '}
                                <span className="font-normal tracking-normal normal-case">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="notes"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Visa pending, travel booked, standby pool…"
                                className="resize-y rounded-xl border-border bg-card px-4 py-3 text-sm transition-all focus-visible:ring-primary/40"
                            />
                            {form.errors.notes ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.notes}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="h-11 flex-1 rounded-xl px-8 font-semibold"
                        disabled={form.processing}
                        onClick={onSubmit}
                    >
                        {form.processing ? 'Saving…' : 'Save Plan'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
