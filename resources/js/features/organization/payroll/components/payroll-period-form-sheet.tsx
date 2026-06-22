import type { InertiaFormProps } from '@inertiajs/react';
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
import type { PayrollPeriodFormData } from '../types';

export function PayrollPeriodFormSheet({
    open,
    onOpenChange,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: InertiaFormProps<PayrollPeriodFormData>;
    onSubmit: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none p-0 glass-card sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">New Payroll Period</SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Create a draft pay period for crew and office payroll runs.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-2">
                        <Label
                            htmlFor="name"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Period name
                        </Label>
                        <Input
                            id="name"
                            placeholder="June 2026"
                            className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                        {form.errors.name ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.name}</div>
                        ) : null}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="start_date"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Start date
                            </Label>
                            <Input
                                id="start_date"
                                type="date"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.start_date}
                                onChange={(e) => form.setData('start_date', e.target.value)}
                            />
                            {form.errors.start_date ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.start_date}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="end_date"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                End date
                            </Label>
                            <Input
                                id="end_date"
                                type="date"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.end_date}
                                onChange={(e) => form.setData('end_date', e.target.value)}
                            />
                            {form.errors.end_date ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.end_date}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label
                            htmlFor="payment_date"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Payment date
                        </Label>
                        <Input
                            id="payment_date"
                            type="date"
                            className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                            value={form.data.payment_date}
                            onChange={(e) => form.setData('payment_date', e.target.value)}
                        />
                        {form.errors.payment_date ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.payment_date}</div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label
                            htmlFor="notes"
                            className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                        >
                            Notes
                        </Label>
                        <Textarea
                            id="notes"
                            placeholder="Optional notes"
                            className="min-h-24 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                        />
                        {form.errors.notes ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.notes}</div>
                        ) : null}
                    </div>
                </div>

                <div className="border-t border-border/60 p-8">
                    <Button className="w-full rounded-xl" disabled={form.processing} onClick={onSubmit}>
                        Create period
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
