import { router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function CalendarToolbar({
    year,
    currentYear,
}: {
    year: number;
    currentYear: number;
}) {
    const navigate = (nextYear: number) => {
        router.get('/attendance/calendar', { year: nextYear }, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="glass-card space-y-3 rounded-2xl border border-border/60 bg-card/80 p-3 dark:border-white/6 dark:bg-white/4">
            <div className="flex items-center justify-between gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9 shrink-0 rounded-xl hover:bg-muted/80 dark:hover:bg-white/10"
                    onClick={() => navigate(year - 1)}
                    title="Previous year"
                >
                    <ChevronLeft className="h-4 w-4" />
                </Button>

                <div className="flex min-w-0 flex-1 items-center justify-center gap-2">
                    <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <CalendarDays className="size-4" />
                    </div>
                    <div className="text-2xl font-extrabold tracking-tight tabular-nums">{year}</div>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9 shrink-0 rounded-xl hover:bg-muted/80 dark:hover:bg-white/10"
                    onClick={() => navigate(year + 1)}
                    title="Next year"
                >
                    <ChevronRight className="h-4 w-4" />
                </Button>
            </div>

            <div className="flex items-center justify-between gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="h-9 flex-1 rounded-xl border-border/60 bg-background/60 text-xs font-semibold dark:border-white/8 dark:bg-white/5"
                    onClick={() => navigate(currentYear)}
                    disabled={year === currentYear}
                >
                    Today
                </Button>
                <div
                    className={cn(
                        'shrink-0 rounded-full px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.14em]',
                        year === currentYear
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'
                            : 'bg-muted/60 text-muted-foreground',
                    )}
                >
                    {year === currentYear ? 'Current year' : 'Historical'}
                </div>
            </div>
        </div>
    );
}
