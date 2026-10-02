import type { InertiaFormProps } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo } from 'react';
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
    PlanningPoolEmployee,
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
    positions,
    employees = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: InertiaFormProps<AssignmentFormData>;
    onSubmit: () => void;
    editing: GanttBar | null;
    relievesEmployeeName: string;
    vessels: PlanningOption[];
    positions: PlanningOption[];
    employees?: PlanningPoolEmployee[];
}): ReactElement {
    const isEdit = editing !== null;

    const availableEmployees = useMemo(() => {
        if (!form.data.position_id) {
            return employees;
        }

        const posId = Number(form.data.position_id);
        const matching = employees.filter((e) => e.position_id === posId);

        if (form.data.employee_id) {
            const currentEmp = employees.find(
                (e) => String(e.id) === form.data.employee_id,
            );

            if (currentEmp && !matching.some((e) => e.id === currentEmp.id)) {
                return [currentEmp, ...matching];
            }
        }

        return matching.length > 0 ? matching : employees;
    }, [employees, form.data.position_id, form.data.employee_id]);

    const plannedDurationDays =
        form.data.planned_join_date !== '' &&
        form.data.planned_leave_date !== ''
            ? assignmentDurationDays(
                  form.data.planned_join_date,
                  form.data.planned_leave_date,
              )
            : null;

    const handlePositionChange = (value: string): void => {
        form.setData({
            ...form.data,
            position_id: value,
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
                            : 'Schedule crew on a vessel and position for the selected dates.'}
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
                                    Crew Planning is the future-planning
                                    workspace. Leave the crew member blank for a
                                    vacant slot, or select a named employee for
                                    Expected Arrival / Join / Sign-Off.
                                </p>
                                <p>
                                    Start Mobilisation begins the operational P0
                                    cycle. Planning forecasts never create P4
                                    actuals, Sea Service, or payroll, and do not
                                    automatically disembark source crew.
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
                                htmlFor="position_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Position *
                            </Label>
                            <AppSelect
                                value={form.data.position_id}
                                onValueChange={handlePositionChange}
                                placeholder={
                                    form.data.vessel_id === ''
                                        ? 'Select vessel first'
                                        : 'Select position'
                                }
                                disabled={form.data.vessel_id === ''}
                                variant="card"
                            >
                                {positions.map((r) => (
                                    <AppSelectItem
                                        key={r.id}
                                        value={String(r.id)}
                                    >
                                        {r.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.position_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.position_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="employee_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Crew member{' '}
                                <span className="font-normal tracking-normal normal-case">
                                    (optional — vacant if blank)
                                </span>
                            </Label>
                            <AppSelect
                                value={form.data.employee_id}
                                onValueChange={(value) =>
                                    form.setData('employee_id', value)
                                }
                                placeholder="Vacant / Unfilled"
                                variant="card"
                            >
                                <AppSelectItem value="">
                                    Vacant / Unfilled
                                </AppSelectItem>
                                {availableEmployees.map((e) => (
                                    <AppSelectItem
                                        key={e.id}
                                        value={String(e.id)}
                                    >
                                        {e.name} ({e.position_name})
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.employee_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.employee_id}
                                </div>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-5 border-t border-border/60 pt-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="planned_arrival_date"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Expected arrival{' '}
                                <span className="font-normal tracking-normal normal-case">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="planned_arrival_date"
                                type="date"
                                value={form.data.planned_arrival_date}
                                onChange={(e) =>
                                    form.setData(
                                        'planned_arrival_date',
                                        e.target.value,
                                    )
                                }
                                className={fieldInputClass}
                            />
                            {form.errors.planned_arrival_date ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.planned_arrival_date}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="planned_join_date"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Expected Vessel Join *
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
                                    Expected Sign-Off *
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
                                Planned onboard duration:{' '}
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
