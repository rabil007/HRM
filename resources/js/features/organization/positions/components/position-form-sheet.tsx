import type { InertiaFormProps } from '@inertiajs/react';
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
import type { DepartmentOption, Position, PositionFormData } from '../types';

export function PositionFormSheet({
    open,
    onOpenChange,
    position,
    departments,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    position: Position | null;
    departments: DepartmentOption[];
    form: InertiaFormProps<PositionFormData>;
    onSubmit: () => void;
}) {
    const availableDepartments = departments ?? [];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {position ? 'Edit Position' : 'New Position'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {position
                            ? 'Update position details.'
                            : 'Add a new position.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="department_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Department (optional)
                            </Label>
                            <AppSelect
                                value={String(form.data.department_id ?? '')}
                                onValueChange={(v) =>
                                    form.setData(
                                        'department_id',
                                        v ? Number(v) : '',
                                    )
                                }
                                variant="card"
                                placeholder="All departments"
                            >
                                <AppSelectItem value="">
                                    All departments
                                </AppSelectItem>
                                {availableDepartments.map((d) => (
                                    <AppSelectItem
                                        key={d.id}
                                        value={String(d.id)}
                                    >
                                        {d.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.department_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.department_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="title"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Title
                            </Label>
                            <Input
                                id="title"
                                placeholder="Software Engineer"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                            />
                            {form.errors.title ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.title}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="status"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Status
                                </Label>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'status',
                                            v as 'active' | 'inactive',
                                        )
                                    }
                                    variant="card"
                                >
                                    <AppSelectItem value="active">
                                        Active
                                    </AppSelectItem>
                                    <AppSelectItem value="inactive">
                                        Inactive
                                    </AppSelectItem>
                                </AppSelect>
                                {form.errors.status ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.status}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="grade"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Grade (optional)
                                </Label>
                                <Input
                                    id="grade"
                                    placeholder="G5"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.grade}
                                    onChange={(e) =>
                                        form.setData('grade', e.target.value)
                                    }
                                />
                                {form.errors.grade ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.grade}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="min_salary"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Min salary
                                </Label>
                                <Input
                                    id="min_salary"
                                    inputMode="decimal"
                                    placeholder="0"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.min_salary}
                                    onChange={(e) =>
                                        form.setData(
                                            'min_salary',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.min_salary ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.min_salary}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="max_salary"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Max salary
                                </Label>
                                <Input
                                    id="max_salary"
                                    inputMode="decimal"
                                    placeholder="0"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.max_salary}
                                    onChange={(e) =>
                                        form.setData(
                                            'max_salary',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.max_salary ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.max_salary}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="h-11 flex-1 rounded-xl px-6 font-semibold"
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {position ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
