import { Calendar, ChevronDown } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

function toDateInputValue(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function endOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth() + 1, 0);
}

export function DateRangeFilter({
    label = 'Period',
    hint,
    from,
    to,
    onChange,
    className,
}: {
    label?: string;
    hint?: string;
    from: string;
    to: string;
    onChange: (values: { from: string; to: string }) => void;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [draftFrom, setDraftFrom] = useState(from);
    const [draftTo, setDraftTo] = useState(to);

    const handleOpenChange = (nextOpen: boolean) => {
        setOpen(nextOpen);

        if (nextOpen) {
            setDraftFrom(from);
            setDraftTo(to);
        }
    };

    const isActive = Boolean(from) || Boolean(to);

    const triggerLabel = useMemo(() => {
        if (from && to) {
            return `${formatDisplayDate(from)} → ${formatDisplayDate(to)}`;
        }

        if (from) {
            return `From ${formatDisplayDate(from)}`;
        }

        if (to) {
            return `Until ${formatDisplayDate(to)}`;
        }

        return label;
    }, [from, label, to]);

    const applyPreset = (
        preset: 'this_month' | 'last_30_days' | 'this_year',
    ) => {
        const today = new Date();

        if (preset === 'this_month') {
            setDraftFrom(toDateInputValue(startOfMonth(today)));
            setDraftTo(toDateInputValue(endOfMonth(today)));

            return;
        }

        if (preset === 'last_30_days') {
            const start = new Date(today);
            start.setDate(start.getDate() - 29);
            setDraftFrom(toDateInputValue(start));
            setDraftTo(toDateInputValue(today));

            return;
        }

        setDraftFrom(`${today.getFullYear()}-01-01`);
        setDraftTo(`${today.getFullYear()}-12-31`);
    };

    const handleApply = () => {
        onChange({ from: draftFrom, to: draftTo });
        setOpen(false);
    };

    const handleClear = () => {
        setDraftFrom('');
        setDraftTo('');
        onChange({ from: '', to: '' });
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    title={hint}
                    className={cn(
                        'group flex h-11 min-w-44 items-center justify-between gap-2 rounded-xl border px-3 text-sm font-medium transition-all focus:ring-2 focus:ring-primary/40 focus:outline-none',
                        isActive
                            ? 'border-primary/30 bg-primary/10 text-primary hover:bg-primary/15'
                            : 'border-white/10 bg-white/5 text-foreground/80 hover:bg-white/10 hover:text-foreground',
                        className,
                    )}
                >
                    <div className="flex min-w-0 items-center gap-2">
                        <Calendar className="h-4 w-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary" />
                        <span className="truncate">{triggerLabel}</span>
                    </div>
                    <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                </button>
            </PopoverTrigger>

            <PopoverContent
                align="start"
                className="w-80 rounded-2xl border border-white/10 bg-popover/95 p-4 shadow-2xl backdrop-blur-xl"
            >
                <div className="space-y-1 border-b border-white/10 pb-3">
                    <p className="text-sm font-semibold text-foreground">
                        {label}
                    </p>
                    {hint ? (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center gap-2 py-3 text-xs">
                    <button
                        type="button"
                        onClick={() => applyPreset('this_month')}
                        className="font-medium text-primary hover:underline"
                    >
                        This month
                    </button>
                    <span className="text-muted-foreground/40">•</span>
                    <button
                        type="button"
                        onClick={() => applyPreset('last_30_days')}
                        className="text-muted-foreground transition-colors hover:text-foreground"
                    >
                        Last 30 days
                    </button>
                    <span className="text-muted-foreground/40">•</span>
                    <button
                        type="button"
                        onClick={() => applyPreset('this_year')}
                        className="text-muted-foreground transition-colors hover:text-foreground"
                    >
                        This year
                    </button>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="date-range-filter-from"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            From
                        </Label>
                        <Input
                            id="date-range-filter-from"
                            type="date"
                            value={draftFrom}
                            onChange={(event) =>
                                setDraftFrom(event.target.value)
                            }
                            className="h-10 rounded-xl"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="date-range-filter-to"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            To
                        </Label>
                        <Input
                            id="date-range-filter-to"
                            type="date"
                            value={draftTo}
                            onChange={(event) => setDraftTo(event.target.value)}
                            className="h-10 rounded-xl"
                        />
                    </div>
                </div>

                <div className="mt-4 flex items-center justify-between border-t border-white/10 pt-3">
                    <button
                        type="button"
                        onClick={handleClear}
                        className="text-xs text-muted-foreground transition-colors hover:text-destructive"
                    >
                        Clear
                    </button>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 rounded-lg text-xs"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className="h-8 rounded-lg bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-sm hover:bg-primary/90"
                            onClick={handleApply}
                        >
                            Apply
                        </Button>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}
