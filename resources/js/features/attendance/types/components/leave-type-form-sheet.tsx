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
import { Switch } from '@/components/ui/switch';
import type { LeaveType, LeaveTypeFormData } from '../types';

const inputClass =
    'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

export function LeaveTypeFormSheet({
    open,
    onOpenChange,
    leaveType,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    leaveType: LeaveType | null;
    form: InertiaFormProps<LeaveTypeFormData>;
    onSubmit: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {leaveType ? 'Edit Type' : 'New Type'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {leaveType
                            ? 'Update type details.'
                            : 'Add a new leave type.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
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
                                    htmlFor="code"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Code
                                </Label>
                                <Input
                                    id="code"
                                    placeholder="AL"
                                    className={inputClass}
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                                {form.errors.code ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.code}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Annual Leave"
                                className={inputClass}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="days_per_year"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Days per year
                                </Label>
                                <Input
                                    id="days_per_year"
                                    inputMode="decimal"
                                    className={inputClass}
                                    value={form.data.days_per_year}
                                    onChange={(e) =>
                                        form.setData(
                                            'days_per_year',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.days_per_year ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.days_per_year}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="max_carry_days"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Max carry days
                                </Label>
                                <Input
                                    id="max_carry_days"
                                    inputMode="numeric"
                                    className={inputClass}
                                    value={form.data.max_carry_days}
                                    onChange={(e) =>
                                        form.setData(
                                            'max_carry_days',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.max_carry_days ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.max_carry_days}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 px-4 py-3">
                            <div>
                                <p className="text-sm font-medium">
                                    Carry forward
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Allow unused days to roll over
                                </p>
                            </div>
                            <Switch
                                checked={form.data.carry_forward}
                                onCheckedChange={(v) =>
                                    form.setData('carry_forward', v)
                                }
                            />
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="color"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Color
                            </Label>
                            <Input
                                id="color"
                                type="color"
                                className="h-11 w-full rounded-xl border-border bg-card p-1"
                                value={form.data.color}
                                onChange={(e) =>
                                    form.setData('color', e.target.value)
                                }
                            />
                            {form.errors.color ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.color}
                                </div>
                            ) : null}
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
                        {leaveType ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
