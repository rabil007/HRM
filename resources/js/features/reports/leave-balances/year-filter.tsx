import { Calendar, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const YEARS_PER_PAGE = 12;

function decadeStart(year: number): number {
    return Math.floor(year / YEARS_PER_PAGE) * YEARS_PER_PAGE;
}

export function LeaveBalanceYearFilter({
    value,
    years,
    onChange,
}: {
    value: string;
    years: number[];
    onChange: (year: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const currentYear = useMemo(() => new Date().getFullYear(), []);
    const selectedYear = Number.parseInt(value, 10);
    const availableYears = useMemo(() => new Set(years), [years]);

    const initialViewStart = useMemo(() => {
        if (!Number.isNaN(selectedYear)) {
            return decadeStart(selectedYear);
        }

        if (years.length > 0) {
            return decadeStart(years[0]);
        }

        return decadeStart(currentYear);
    }, [selectedYear, years, currentYear]);

    const [viewStart, setViewStart] = useState(initialViewStart);

    const handleOpenChange = (nextOpen: boolean) => {
        setOpen(nextOpen);

        if (nextOpen) {
            setViewStart(initialViewStart);
        }
    };

    const pageYears = useMemo(
        () =>
            Array.from(
                { length: YEARS_PER_PAGE },
                (_, index) => viewStart + index,
            ),
        [viewStart],
    );

    const minAvailable = years.length > 0 ? Math.min(...years) : currentYear;
    const maxAvailable = years.length > 0 ? Math.max(...years) : currentYear;
    const canGoPrev = viewStart > decadeStart(minAvailable);
    const canGoNext = viewStart + YEARS_PER_PAGE - 1 < maxAvailable;

    const selectYear = (year: number) => {
        onChange(String(year));
        setOpen(false);
    };

    const triggerLabel = Number.isNaN(selectedYear)
        ? 'Year'
        : String(selectedYear);

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'group flex h-11 min-w-[7.5rem] items-center justify-between gap-2 rounded-xl border px-3 text-sm font-semibold transition-all focus:ring-2 focus:ring-primary/40 focus:outline-none',
                        value
                            ? 'border-primary/30 bg-primary/10 text-primary hover:bg-primary/15'
                            : 'border-white/10 bg-white/5 text-foreground/80 hover:bg-white/10 hover:text-foreground',
                    )}
                >
                    <div className="flex items-center gap-2 truncate">
                        <Calendar className="h-4 w-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary" />
                        <span className="truncate">{triggerLabel}</span>
                    </div>
                    <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                </button>
            </PopoverTrigger>

            <PopoverContent
                align="start"
                className="w-72 rounded-2xl border border-white/10 bg-popover/95 p-4 shadow-2xl backdrop-blur-xl"
            >
                <div className="flex items-center justify-between border-b border-white/10 pb-3">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground"
                        disabled={!canGoPrev}
                        onClick={() =>
                            setViewStart((start) => start - YEARS_PER_PAGE)
                        }
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <span className="text-sm font-bold tracking-tight text-foreground">
                        {viewStart}–{viewStart + YEARS_PER_PAGE - 1}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground"
                        disabled={!canGoNext}
                        onClick={() =>
                            setViewStart((start) => start + YEARS_PER_PAGE)
                        }
                    >
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>

                {availableYears.has(currentYear) ? (
                    <div className="flex items-center justify-between py-2 text-xs">
                        <button
                            type="button"
                            onClick={() => selectYear(currentYear)}
                            className="font-medium text-primary hover:underline"
                        >
                            This year
                        </button>
                    </div>
                ) : null}

                <div className="grid grid-cols-3 gap-2 py-2">
                    {pageYears.map((year) => {
                        const isAvailable = availableYears.has(year);
                        const isSelected = selectedYear === year;
                        const isCurrent = year === currentYear;

                        return (
                            <button
                                key={year}
                                type="button"
                                disabled={!isAvailable}
                                onClick={() => selectYear(year)}
                                className={cn(
                                    'relative flex h-10 items-center justify-center rounded-xl text-xs font-semibold transition-all duration-150',
                                    isSelected
                                        ? 'bg-primary font-bold text-primary-foreground shadow-md shadow-primary/25'
                                        : isAvailable
                                          ? 'text-foreground/75 hover:bg-white/10 hover:text-foreground'
                                          : 'cursor-not-allowed text-muted-foreground/30',
                                    isCurrent &&
                                        !isSelected &&
                                        isAvailable &&
                                        'text-primary ring-1 ring-primary/40',
                                )}
                            >
                                {year}
                                {isCurrent && !isSelected && isAvailable ? (
                                    <span className="absolute bottom-1 h-1 w-1 rounded-full bg-primary" />
                                ) : null}
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}
