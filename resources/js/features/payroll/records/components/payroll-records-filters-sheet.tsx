import { useEffect, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { PayrollCategoryOption } from '../../types';
import type { PayrollRecordsFilters } from '../types';

export function PayrollRecordsFiltersSheet({
    open,
    onOpenChange,
    filters,
    payrollCategories,
    statusOptions,
    onApply,
    onClear,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: PayrollRecordsFilters;
    payrollCategories: PayrollCategoryOption[];
    statusOptions: Array<{ value: string; label: string }>;
    onApply: (filters: PayrollRecordsFilters) => void;
    onClear: () => void;
}) {
    const [draft, setDraft] = useState(filters);

    useEffect(() => {
        if (open) {
            setDraft(filters);
        }
    }, [open, filters]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="glass-card w-full sm:max-w-md p-0 flex flex-col rounded-none">
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">Filter records</SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Narrow payroll records by category, status, or period dates.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Category
                        </Label>
                        <AppSelect
                            value={draft.category || 'all'}
                            onValueChange={(value) =>
                                setDraft((current) => ({
                                    ...current,
                                    category: value === 'all' ? '' : value,
                                }))
                            }
                            variant="card"
                        >
                            <AppSelectItem value="all">All categories</AppSelectItem>
                            {payrollCategories.map((category) => (
                                <AppSelectItem key={category.value} value={category.value}>
                                    {category.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Record status
                        </Label>
                        <AppSelect
                            value={draft.status || 'all'}
                            onValueChange={(value) =>
                                setDraft((current) => ({
                                    ...current,
                                    status: value === 'all' ? '' : value,
                                }))
                            }
                            variant="card"
                        >
                            <AppSelectItem value="all">All statuses</AppSelectItem>
                            {statusOptions.map((option) => (
                                <AppSelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Period from
                            </Label>
                            <Input
                                type="date"
                                value={draft.date_from}
                                onChange={(event) =>
                                    setDraft((current) => ({ ...current, date_from: event.target.value }))
                                }
                                className="h-11 rounded-xl"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Period to
                            </Label>
                            <Input
                                type="date"
                                value={draft.date_to}
                                onChange={(event) =>
                                    setDraft((current) => ({ ...current, date_to: event.target.value }))
                                }
                                className="h-11 rounded-xl"
                            />
                        </div>
                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 p-6">
                    <Button variant="outline" className="flex-1 rounded-xl" onClick={onClear}>
                        Clear
                    </Button>
                    <Button className="flex-1 rounded-xl" onClick={() => onApply(draft)}>
                        Apply filters
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
