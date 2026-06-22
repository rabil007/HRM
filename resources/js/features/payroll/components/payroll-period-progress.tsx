import { cn } from '@/lib/utils';

export function PayrollPeriodProgress({
    value,
    className,
    barClassName,
}: {
    value: number;
    className?: string;
    barClassName?: string;
}) {
    const clamped = Math.max(0, Math.min(100, value));

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="h-2 overflow-hidden rounded-full bg-muted/60 dark:bg-white/10">
                <div
                    className={cn(
                        'h-full rounded-full bg-linear-to-r from-primary to-primary/70 transition-all duration-500',
                        barClassName,
                    )}
                    style={{ width: `${clamped}%` }}
                />
            </div>
        </div>
    );
}
