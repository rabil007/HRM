import { CheckCircle2, Clock, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

const STATUS_ITEMS: {
    value: 'pending' | 'approved' | 'rejected';
    label: string;
    icon: LucideIcon;
    activeClass: string;
}[] = [
    {
        value: 'approved',
        label: 'Approved',
        icon: CheckCircle2,
        activeClass:
            'border-emerald-500/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    },
    {
        value: 'pending',
        label: 'Pending',
        icon: Clock,
        activeClass:
            'border-amber-500/40 bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    {
        value: 'rejected',
        label: 'Rejected',
        icon: XCircle,
        activeClass:
            'border-red-500/40 bg-red-500/15 text-red-700 dark:text-red-300',
    },
];

export function LeaveReportStatusFilters({
    activeStatus,
    onSelect,
}: {
    activeStatus: string;
    onSelect: (status: string) => void;
}) {
    return (
        <div
            className="flex flex-wrap items-center gap-1.5"
            role="group"
            aria-label="Filter by leave status"
        >
            {STATUS_ITEMS.map((item) => {
                const isActive = activeStatus === item.value;
                const Icon = item.icon;

                return (
                    <button
                        key={item.value}
                        type="button"
                        onClick={() => onSelect(isActive ? '' : item.value)}
                        aria-pressed={isActive}
                        aria-label={
                            isActive
                                ? `Clear ${item.label.toLowerCase()} filter and show all leave requests`
                                : `Show ${item.label.toLowerCase()} leave requests`
                        }
                        className={cn(
                            'inline-flex h-11 items-center gap-1.5 rounded-xl border border-input bg-background/50 px-3 text-sm font-medium text-muted-foreground shadow-xs transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:border-white/10 dark:bg-white/5',
                            isActive && item.activeClass,
                        )}
                    >
                        <Icon
                            className="size-3.5 shrink-0 opacity-80"
                            aria-hidden
                        />
                        <span>{item.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
