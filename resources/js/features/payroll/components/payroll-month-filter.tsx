import { Calendar, Check, ChevronDown, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const MONTH_NAMES = [
    { short: 'Jan', full: 'January' },
    { short: 'Feb', full: 'February' },
    { short: 'Mar', full: 'March' },
    { short: 'Apr', full: 'April' },
    { short: 'May', full: 'May' },
    { short: 'Jun', full: 'June' },
    { short: 'Jul', full: 'July' },
    { short: 'Aug', full: 'August' },
    { short: 'Sep', full: 'September' },
    { short: 'Oct', full: 'October' },
    { short: 'Nov', full: 'November' },
    { short: 'Dec', full: 'December' },
];

function formatMonthLabel(ym: string): string {
    const [year, month] = ym.split('-');
    const mIdx = parseInt(month, 10) - 1;
    const name = MONTH_NAMES[mIdx]?.short ?? month;
    return `${name} ${year}`;
}

export function PayrollMonthFilter({
    selectedMonths = [],
    isAll = false,
    onChange,
    onClearToAll,
}: {
    selectedMonths?: string[];
    isAll?: boolean;
    onChange: (months: string[]) => void;
    onClearToAll: () => void;
}) {
    const [open, setOpen] = useState(false);

    const today = useMemo(() => new Date(), []);
    const currentYear = today.getFullYear();
    const currentYearMonth = `${currentYear}-${String(today.getMonth() + 1).padStart(2, '0')}`;

    // Year being viewed inside the popover
    const initialViewYear = useMemo(() => {
        if (selectedMonths.length > 0) {
            const sorted = [...selectedMonths].sort();
            const latest = sorted[sorted.length - 1];
            const parsedYear = parseInt(latest.split('-')[0], 10);
            if (!Number.isNaN(parsedYear)) {
                return parsedYear;
            }
        }
        return currentYear;
    }, [selectedMonths, currentYear]);

    const [viewYear, setViewYear] = useState(initialViewYear);
    const [draftMonths, setDraftMonths] = useState<string[]>(selectedMonths);

    // Sync draft with incoming prop whenever popover opens or selectedMonths change
    useEffect(() => {
        if (open) {
            setDraftMonths(selectedMonths);
            setViewYear(initialViewYear);
        }
    }, [open, selectedMonths, initialViewYear]);

    const toggleMonth = (ym: string) => {
        setDraftMonths((prev) => {
            if (prev.includes(ym)) {
                return prev.filter((m) => m !== ym);
            }
            return [...prev, ym].sort();
        });
    };

    const handleSelectCurrentMonth = () => {
        onChange([currentYearMonth]);
        setOpen(false);
    };

    const handleSelectAllInYear = () => {
        const yearMonths = Array.from({ length: 12 }, (_, i) => {
            const m = String(i + 1).padStart(2, '0');
            return `${viewYear}-${m}`;
        });
        setDraftMonths((prev) => {
            const otherYears = prev.filter((m) => !m.startsWith(`${viewYear}-`));
            return [...otherYears, ...yearMonths].sort();
        });
    };

    const handleApply = () => {
        if (draftMonths.length === 0) {
            onClearToAll();
        } else {
            onChange(draftMonths);
        }
        setOpen(false);
    };

    const handleClearAll = () => {
        setDraftMonths([]);
    };

    // Label for the trigger button
    const triggerLabel = useMemo(() => {
        if (isAll) {
            return 'All periods';
        }
        if (selectedMonths.length === 0) {
            return 'Select months';
        }
        if (selectedMonths.length === 1) {
            return formatMonthLabel(selectedMonths[0]);
        }
        if (selectedMonths.length === 2) {
            return `${formatMonthLabel(selectedMonths[0])}, ${formatMonthLabel(selectedMonths[1])}`;
        }
        return `${selectedMonths.length} months selected`;
    }, [selectedMonths, isAll]);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'group flex h-11 min-w-44 items-center justify-between gap-2 rounded-xl border px-3 text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-primary/40',
                        !isAll && selectedMonths.length > 0
                            ? 'border-primary/30 bg-primary/10 text-primary hover:bg-primary/15'
                            : 'border-white/10 bg-white/5 text-foreground/80 hover:bg-white/10 hover:text-foreground',
                    )}
                >
                    <div className="flex items-center gap-2 truncate">
                        <Calendar className="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-primary transition-colors" />
                        <span className="truncate">{triggerLabel}</span>
                    </div>
                    <div className="flex items-center gap-1.5 shrink-0">
                        {!isAll && selectedMonths.length > 2 && (
                            <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold text-primary-foreground">
                                {selectedMonths.length}
                            </span>
                        )}
                        <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
                    </div>
                </button>
            </PopoverTrigger>

            <PopoverContent
                align="start"
                className="w-80 rounded-2xl border border-white/10 bg-popover/95 p-4 shadow-2xl backdrop-blur-xl"
            >
                {/* Year navigation */}
                <div className="flex items-center justify-between pb-3 border-b border-white/10">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground"
                        onClick={() => setViewYear((y) => y - 1)}
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <span className="text-sm font-bold tracking-tight text-foreground">
                        {viewYear}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground"
                        onClick={() => setViewYear((y) => y + 1)}
                    >
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>

                {/* Quick actions for year */}
                <div className="flex items-center justify-between py-2 text-xs">
                    <button
                        type="button"
                        onClick={handleSelectCurrentMonth}
                        className="text-primary hover:underline font-medium"
                    >
                        This month
                    </button>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={handleSelectAllInYear}
                            className="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            All of {viewYear}
                        </button>
                        {draftMonths.length > 0 && (
                            <>
                                <span className="text-muted-foreground/40">•</span>
                                <button
                                    type="button"
                                    onClick={handleClearAll}
                                    className="text-muted-foreground hover:text-destructive transition-colors"
                                >
                                    Clear
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {/* Month Grid */}
                <div className="grid grid-cols-3 gap-2 py-2">
                    {MONTH_NAMES.map((m, idx) => {
                        const ym = `${viewYear}-${String(idx + 1).padStart(2, '0')}`;
                        const isSelected = draftMonths.includes(ym);
                        const isCurrent = ym === currentYearMonth;

                        return (
                            <button
                                key={ym}
                                type="button"
                                onClick={() => toggleMonth(ym)}
                                className={cn(
                                    'relative flex h-10 items-center justify-center rounded-xl text-xs font-semibold transition-all duration-150',
                                    isSelected
                                        ? 'bg-primary text-primary-foreground shadow-md shadow-primary/25 font-bold'
                                        : 'text-foreground/75 hover:bg-white/10 hover:text-foreground',
                                    isCurrent && !isSelected && 'ring-1 ring-primary/40 text-primary',
                                )}
                            >
                                <span>{m.short}</span>
                                {isSelected && (
                                    <Check className="absolute top-1 right-1 h-3 w-3 opacity-80" />
                                )}
                                {isCurrent && !isSelected && (
                                    <span className="absolute bottom-1 h-1 w-1 rounded-full bg-primary" />
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* Popover footer */}
                <div className="mt-3 flex items-center justify-between pt-3 border-t border-white/10">
                    <span className="text-xs text-muted-foreground">
                        {draftMonths.length === 0
                            ? 'No months selected'
                            : `${draftMonths.length} selected`}
                    </span>
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
