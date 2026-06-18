import type { InertiaFormProps } from '@inertiajs/react';
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
import type {
    AssignmentFormData,
    GanttBar,
    GanttVesselGroup,
    PlanningOption,
    PlanningPoolEmployee,
} from '../types';

export function AssignCrewSheet({
    open,
    onOpenChange,
    form,
    onSubmit,
    editing,
    vessels,
    ranks,
    rows,
    employees,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: InertiaFormProps<AssignmentFormData>;
    onSubmit: () => void;
    editing: GanttBar | null;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    rows: GanttVesselGroup[];
    employees: PlanningPoolEmployee[];
}) {
    const isEdit = editing !== null;

    const availableRanks = useMemo(() => {
        if (form.data.vessel_id === '') {
            return ranks;
        }

        const vesselGroup = rows.find(
            (group) => String(group.vessel_id) === form.data.vessel_id,
        );
        const manningRankIds = new Set(vesselGroup?.ranks.map((rank) => rank.rank_id) ?? []);

        return ranks.filter((rank) => manningRankIds.has(rank.id));
    }, [form.data.vessel_id, ranks, rows]);

    const availableEmployees = useMemo(() => {
        if (form.data.rank_id !== '') {
            return employees.filter((employee) => employee.rank_id === Number(form.data.rank_id));
        }

        if (form.data.vessel_id === '') {
            return employees;
        }

        const manningRankIds = new Set(availableRanks.map((rank) => rank.id));

        return employees.filter((employee) => manningRankIds.has(employee.rank_id));
    }, [availableRanks, employees, form.data.rank_id, form.data.vessel_id]);

    const handleRankChange = (value: string): void => {
        const selectedEmployee =
            form.data.employee_id !== ''
                ? employees.find((employee) => employee.id === Number(form.data.employee_id))
                : undefined;
        const employeeStillMatches =
            selectedEmployee === undefined || selectedEmployee.rank_id === Number(value);

        form.setData({
            ...form.data,
            rank_id: value,
            employee_id: employeeStillMatches ? form.data.employee_id : '',
        });
    };

    const handleEmployeeChange = (value: string): void => {
        if (value === '') {
            form.setData('employee_id', '');

            return;
        }

        const employee = employees.find((entry) => entry.id === Number(value));

        if (employee === undefined) {
            form.setData('employee_id', value);

            return;
        }

        const rankIsAvailable = availableRanks.some((rank) => rank.id === employee.rank_id);

        form.setData({
            ...form.data,
            employee_id: value,
            rank_id: rankIsAvailable ? String(employee.rank_id) : form.data.rank_id,
        });
    };
    const handleVesselChange = (value: string): void => {
        const vesselGroup = rows.find((group) => String(group.vessel_id) === value);
        const manningRankIds = new Set(vesselGroup?.ranks.map((rank) => rank.rank_id) ?? []);
        const rankStillValid =
            form.data.rank_id !== '' && manningRankIds.has(Number(form.data.rank_id));
        const selectedEmployee =
            form.data.employee_id !== ''
                ? employees.find((employee) => employee.id === Number(form.data.employee_id))
                : undefined;
        const nextRankId = rankStillValid ? form.data.rank_id : '';
        const employeeStillMatches =
            selectedEmployee === undefined ||
            (nextRankId !== '' && selectedEmployee.rank_id === Number(nextRankId));

        form.setData({
            ...form.data,
            vessel_id: value,
            rank_id: nextRankId,
            employee_id: employeeStillMatches ? form.data.employee_id : '',
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {isEdit ? 'Edit planned assignment' : 'Plan crew assignment'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {isEdit
                            ? 'Update the planned assignment details.'
                            : 'Schedule crew on a vessel and rank for the selected dates.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    {/* Vessel */}
                    <div className="space-y-2">
                        <Label
                            htmlFor="vessel_id"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Vessel <span className="text-destructive">*</span>
                        </Label>
                        <AppSelect
                            value={form.data.vessel_id}
                            onValueChange={handleVesselChange}
                            placeholder="Select vessel…"
                        >
                            {vessels.map((v) => (
                                <AppSelectItem key={v.id} value={String(v.id)}>
                                    {v.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.vessel_id ? (
                            <p className="text-xs font-medium text-destructive">
                                {form.errors.vessel_id}
                            </p>
                        ) : null}
                    </div>

                    {/* Rank */}
                    <div className="space-y-2">
                        <Label
                            htmlFor="rank_id"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Rank <span className="text-destructive">*</span>
                        </Label>
                        <AppSelect
                            value={form.data.rank_id}
                            onValueChange={handleRankChange}
                            placeholder={
                                form.data.vessel_id === ''
                                    ? 'Select vessel first…'
                                    : availableRanks.length === 0
                                      ? 'No ranks configured for this vessel'
                                      : 'Select rank…'
                            }
                            disabled={form.data.vessel_id === '' || availableRanks.length === 0}
                        >
                            {availableRanks.map((r) => (
                                <AppSelectItem key={r.id} value={String(r.id)}>
                                    {r.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.data.vessel_id !== '' && availableRanks.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                Configure ranks for this vessel in Vessel Manning first.
                            </p>
                        ) : null}
                        {form.errors.rank_id ? (
                            <p className="text-xs font-medium text-destructive">
                                {form.errors.rank_id}
                            </p>
                        ) : null}
                    </div>

                    {/* Employee */}
                    <div className="space-y-2">
                        <Label
                            htmlFor="employee_id"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Crew member{' '}
                            <span className="font-normal normal-case text-muted-foreground/60">
                                (optional — leave blank for vacant slot)
                            </span>
                        </Label>
                        <AppSelect
                            value={form.data.employee_id}
                            onValueChange={handleEmployeeChange}
                            placeholder={
                                form.data.rank_id === ''
                                    ? 'Select rank first…'
                                    : availableEmployees.length === 0
                                      ? 'No matching crew for this rank'
                                      : 'Search and select crew…'
                            }
                            disabled={form.data.rank_id === '' || availableEmployees.length === 0}
                        >
                            <AppSelectItem value="">— Vacant slot —</AppSelectItem>
                            {availableEmployees.map((employee) => (
                                <AppSelectItem key={employee.id} value={String(employee.id)}>
                                    {employee.name} · {employee.rank_name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.data.rank_id !== '' && availableEmployees.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                No crew match the selected rank.
                            </p>
                        ) : null}
                        {form.errors.employee_id ? (
                            <p className="text-xs font-medium text-destructive">
                                {form.errors.employee_id}
                            </p>
                        ) : null}
                    </div>

                    {/* Dates */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="planned_join_date"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Planned join <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="planned_join_date"
                                type="date"
                                value={form.data.planned_join_date}
                                onChange={(e) => form.setData('planned_join_date', e.target.value)}
                                className="h-10 rounded-xl border-border/60 bg-background text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                            />
                            {form.errors.planned_join_date ? (
                                <p className="text-xs font-medium text-destructive">
                                    {form.errors.planned_join_date}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="planned_leave_date"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Planned leave <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="planned_leave_date"
                                type="date"
                                value={form.data.planned_leave_date}
                                onChange={(e) => form.setData('planned_leave_date', e.target.value)}
                                className="h-10 rounded-xl border-border/60 bg-background text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                            />
                            {form.errors.planned_leave_date ? (
                                <p className="text-xs font-medium text-destructive">
                                    {form.errors.planned_leave_date}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    {/* Notes */}
                    <div className="space-y-2">
                        <Label
                            htmlFor="notes"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Notes
                        </Label>
                        <Textarea
                            id="notes"
                            rows={3}
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            placeholder="Visa pending, travel booked, standby pool…"
                            className="resize-y rounded-xl border-border/60 bg-background px-4 py-3 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                        />
                        {form.errors.notes ? (
                            <p className="text-xs font-medium text-destructive">
                                {form.errors.notes}
                            </p>
                        ) : null}
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
                        {form.processing
                            ? 'Saving…'
                            : isEdit
                              ? 'Save changes'
                              : 'Create assignment'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
