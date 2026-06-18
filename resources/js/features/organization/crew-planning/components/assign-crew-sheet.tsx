import type { InertiaFormProps } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
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
import type { AssignmentFormData, GanttBar, PlanningOption, PlanningPoolEmployee } from '../types';

export function AssignCrewSheet({
    open,
    onOpenChange,
    form,
    onSubmit,
    editing,
    vessels,
    ranks,
    employees,
    canConfirm = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: InertiaFormProps<AssignmentFormData>;
    onSubmit: () => void;
    editing: GanttBar | null;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    employees: PlanningPoolEmployee[];
    canConfirm?: boolean;
    onConfirm?: () => void;
}) {
    const isEdit = editing !== null;
    const showConfirm =
        isEdit && canConfirm && form.data.employee_id !== '' && editing.employee_id != null;

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
                            ? 'Update the draft assignment details.'
                            : 'Create a draft assignment for future deployment planning.'}
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
                            onValueChange={(v) => form.setData('vessel_id', v)}
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
                            onValueChange={(v) => form.setData('rank_id', v)}
                            placeholder="Select rank…"
                        >
                            {ranks.map((r) => (
                                <AppSelectItem key={r.id} value={String(r.id)}>
                                    {r.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
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
                            onValueChange={(v) => form.setData('employee_id', v)}
                            placeholder="Search and select crew…"
                        >
                            <AppSelectItem value="">— Vacant slot —</AppSelectItem>
                            {employees.map((e) => (
                                <AppSelectItem key={e.id} value={String(e.id)}>
                                    {e.name} · {e.rank_name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
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

                <div className="flex flex-col gap-3 border-t border-border/60 bg-background/40 p-6">
                    {showConfirm ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11 w-full gap-2 rounded-xl font-semibold"
                            disabled={form.processing}
                            onClick={onConfirm}
                        >
                            <CheckCircle2 className="h-4 w-4" />
                            Confirm to deployment
                        </Button>
                    ) : null}
                    <div className="flex gap-3">
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
                </div>
            </SheetContent>
        </Sheet>
    );
}
