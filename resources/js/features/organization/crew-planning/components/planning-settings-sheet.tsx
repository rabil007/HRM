import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
import { updateSettings as updatePlanningSettings } from '@/actions/App/Http/Controllers/Organization/CrewPlanningController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { PlanningOption, PlanningSettings } from '../types';

type FormData = {
    pool_department_ids: number[];
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    departments: PlanningOption[];
    settings: PlanningSettings;
};

export function PlanningSettingsSheet({
    open,
    onOpenChange,
    departments,
    settings,
}: Props): ReactElement {
    const form = useForm<FormData>({
        pool_department_ids: settings.pool_department_ids,
    });

    useEffect(() => {
        if (open) {
            form.setData('pool_department_ids', settings.pool_department_ids);
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, settings.pool_department_ids]);

    const selectedSet = new Set(form.data.pool_department_ids);

    const toggleDepartment = (departmentId: number, checked: boolean): void => {
        if (checked) {
            form.setData('pool_department_ids', [...selectedSet, departmentId]);
            return;
        }

        form.setData(
            'pool_department_ids',
            form.data.pool_department_ids.filter((id) => id !== departmentId),
        );
    };

    const handleSubmit = (): void => {
        form.put(updatePlanningSettings.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const allSelected =
        departments.length > 0 && departments.every((d) => selectedSet.has(d.id));
    const noneSelected = form.data.pool_department_ids.length === 0;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="flex w-full flex-col rounded-none p-0 sm:max-w-md">
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Planning settings
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Choose which departments appear in the Available crew pool and assignment
                        picker. Leave all unchecked to include every active employee.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-4 overflow-y-auto p-8">
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Available crew departments
                        </p>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-xs"
                                disabled={departments.length === 0}
                                onClick={() =>
                                    form.setData(
                                        'pool_department_ids',
                                        departments.map((d) => d.id),
                                    )
                                }
                            >
                                Select all
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-xs"
                                onClick={() => form.setData('pool_department_ids', [])}
                            >
                                Clear
                            </Button>
                        </div>
                    </div>

                    {noneSelected ? (
                        <p className="rounded-xl border border-dashed px-3 py-2 text-xs text-muted-foreground">
                            No departments selected — all active employees are shown in Available
                            crew.
                        </p>
                    ) : null}

                    {allSelected && departments.length > 0 ? (
                        <p className="rounded-xl border border-dashed px-3 py-2 text-xs text-muted-foreground">
                            All departments selected — same as showing every active employee.
                        </p>
                    ) : null}

                    {departments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No active departments found for this company.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {departments.map((department) => {
                                const checked = selectedSet.has(department.id);

                                return (
                                    <label
                                        key={department.id}
                                        className="flex cursor-pointer items-center gap-3 rounded-xl border border-border/60 px-3 py-2.5 hover:bg-muted/40"
                                    >
                                        <Checkbox
                                            checked={checked}
                                            onCheckedChange={(value) =>
                                                toggleDepartment(department.id, value === true)
                                            }
                                        />
                                        <span className="text-sm font-medium">{department.name}</span>
                                    </label>
                                );
                            })}
                        </div>
                    )}

                    {form.errors.pool_department_ids ? (
                        <p className="text-xs font-medium text-destructive">
                            {form.errors.pool_department_ids}
                        </p>
                    ) : null}
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="h-11 flex-1 rounded-xl font-semibold"
                        disabled={form.processing}
                        onClick={handleSubmit}
                    >
                        {form.processing ? 'Saving…' : 'Save settings'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
