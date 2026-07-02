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
import type { SalaryInputTypeFormData, SalaryInputTypeRecord } from '../types';

const inputClass =
    'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

export function SalaryInputTypeFormSheet({
    open,
    onOpenChange,
    salaryInputType,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    salaryInputType: SalaryInputTypeRecord | null;
    form: InertiaFormProps<SalaryInputTypeFormData>;
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
                        {salaryInputType
                            ? 'Edit salary input type'
                            : 'New salary input type'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {salaryInputType
                            ? 'Update how this type appears on pay records.'
                            : 'Add a reusable addition or deduction type for office payroll.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Status
                                </Label>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'status',
                                            value as 'active' | 'inactive',
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
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Code
                                </Label>
                                <Input
                                    placeholder="bonus"
                                    className={inputClass}
                                    value={form.data.code}
                                    onChange={(event) =>
                                        form.setData(
                                            'code',
                                            event.target.value
                                                .toLowerCase()
                                                .replace(/\s+/g, '_'),
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
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Name
                            </Label>
                            <Input
                                placeholder="Performance bonus"
                                className={inputClass}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 px-4 py-3">
                            <div>
                                <p className="text-sm font-medium">Addition</p>
                                <p className="text-xs text-muted-foreground">
                                    Increases gross pay instead of deducting
                                    from net
                                </p>
                            </div>
                            <Switch
                                checked={form.data.is_addition}
                                onCheckedChange={(value) =>
                                    form.setData('is_addition', value)
                                }
                            />
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
                        type="button"
                        className="h-11 flex-1 rounded-xl px-6 font-semibold"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {salaryInputType ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
