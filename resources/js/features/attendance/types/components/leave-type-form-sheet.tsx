import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import type { LeaveType, LeaveTypeFormData } from '../types';

function FieldLabel({ htmlFor, children }: { htmlFor: string; children: string }) {
    return (
        <Label htmlFor={htmlFor} className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
            {children}
        </Label>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? <div className="text-xs font-medium text-destructive">{message}</div> : null;
}

const inputClass = 'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

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
            <SheetContent side="right" className="w-full sm:max-w-xl p-0 flex flex-col glass-card rounded-none">
                <SheetHeader className="p-8 pb-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {leaveType ? 'Edit attendance type' : 'New attendance type'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        Configure leave categories such as annual leave or sick leave.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Basic</p>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2 col-span-2 sm:col-span-1">
                                <FieldLabel htmlFor="name">Name</FieldLabel>
                                <Input
                                    id="name"
                                    placeholder="Annual Leave"
                                    className={inputClass}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                                <FieldError message={form.errors.name} />
                            </div>

                            <div className="space-y-2 col-span-2 sm:col-span-1">
                                <FieldLabel htmlFor="code">Code</FieldLabel>
                                <Input
                                    id="code"
                                    placeholder="AL"
                                    className={inputClass}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                />
                                <FieldError message={form.errors.code} />
                            </div>

                            <div className="space-y-2">
                                <FieldLabel htmlFor="color">Color</FieldLabel>
                                <Input
                                    id="color"
                                    type="color"
                                    className="h-11 w-full rounded-xl border-border bg-card p-1"
                                    value={form.data.color}
                                    onChange={(e) => form.setData('color', e.target.value)}
                                />
                                <FieldError message={form.errors.color} />
                            </div>

                            <div className="space-y-2">
                                <FieldLabel htmlFor="status">Status</FieldLabel>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(v) => form.setData('status', v as 'active' | 'inactive')}
                                    variant="card"
                                >
                                    <AppSelectItem value="active">Active</AppSelectItem>
                                    <AppSelectItem value="inactive">Inactive</AppSelectItem>
                                </AppSelect>
                                <FieldError message={form.errors.status} />
                            </div>
                        </div>
                    </div>

                    <div className="space-y-5">
                        <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Entitlement</p>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <FieldLabel htmlFor="days_per_year">Days per year</FieldLabel>
                                <Input
                                    id="days_per_year"
                                    inputMode="decimal"
                                    className={inputClass}
                                    value={form.data.days_per_year}
                                    onChange={(e) => form.setData('days_per_year', e.target.value)}
                                />
                                <FieldError message={form.errors.days_per_year} />
                            </div>

                            <div className="space-y-2">
                                <FieldLabel htmlFor="accrual_method">Accrual method</FieldLabel>
                                <AppSelect
                                    value={form.data.accrual_method}
                                    onValueChange={(v) =>
                                        form.setData('accrual_method', v as LeaveTypeFormData['accrual_method'])
                                    }
                                    variant="card"
                                >
                                    <AppSelectItem value="upfront">Upfront</AppSelectItem>
                                    <AppSelectItem value="monthly">Monthly</AppSelectItem>
                                    <AppSelectItem value="none">None</AppSelectItem>
                                </AppSelect>
                                <FieldError message={form.errors.accrual_method} />
                            </div>

                            <div className="space-y-2">
                                <FieldLabel htmlFor="max_carry_days">Max carry days</FieldLabel>
                                <Input
                                    id="max_carry_days"
                                    inputMode="numeric"
                                    className={inputClass}
                                    value={form.data.max_carry_days}
                                    onChange={(e) => form.setData('max_carry_days', e.target.value)}
                                />
                                <FieldError message={form.errors.max_carry_days} />
                            </div>

                            <div className="flex items-center justify-between rounded-xl border border-border/60 px-4 py-3">
                                <div>
                                    <p className="text-sm font-medium">Carry forward</p>
                                    <p className="text-xs text-muted-foreground">Allow unused days to roll over</p>
                                </div>
                                <Switch
                                    checked={form.data.carry_forward}
                                    onCheckedChange={(v) => form.setData('carry_forward', v)}
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-border/60 bg-background/40 flex gap-3">
                    <Button
                        type="button"
                        variant="ghost"
                        className="rounded-xl h-11 px-6 text-muted-foreground flex-1"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="rounded-xl h-11 px-6 flex-1 font-semibold"
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
