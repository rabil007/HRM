import type {
    ReliefDeskFocus,
    ReliefDeskSummary,
} from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

const ITEMS: Array<{
    key: ReliefDeskFocus;
    label: string;
    getValue: (summary: ReliefDeskSummary) => number;
    tone: string;
}> = [
    {
        key: 'needs_relief',
        label: 'Needs Relief',
        getValue: (summary) => summary.needs_relief,
        tone: 'text-red-600 dark:text-red-300',
    },
    {
        key: 'critical',
        label: 'Critical',
        getValue: (summary) => summary.critical,
        tone: 'text-red-600 dark:text-red-300',
    },
    {
        key: 'not_ready',
        label: 'Not Ready',
        getValue: (summary) => summary.not_ready,
        tone: 'text-amber-600 dark:text-amber-300',
    },
    {
        key: 'signoff_14',
        label: 'Next 14 Days',
        getValue: (summary) => summary.signoff_14,
        tone: 'text-amber-700 dark:text-amber-200',
    },
    {
        key: 'ready',
        label: 'Ready',
        getValue: (summary) => summary.ready,
        tone: 'text-emerald-600 dark:text-emerald-300',
    },
    {
        key: 'overdue',
        label: 'Overdue',
        getValue: (summary) => summary.overdue,
        tone: 'text-red-700 dark:text-red-200',
    },
];

export function ReliefDeskSummaryStrip({
    summary,
    activeFocus,
    onSelect,
}: {
    summary: ReliefDeskSummary;
    activeFocus: ReliefDeskFocus;
    onSelect: (focus: ReliefDeskFocus) => void;
}) {
    return (
        <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label="Relief desk quick views"
        >
            {ITEMS.map((item) => {
                const isActive = activeFocus === item.key;
                const value = item.getValue(summary);

                return (
                    <button
                        key={item.key}
                        type="button"
                        aria-pressed={isActive}
                        onClick={() => onSelect(isActive ? '' : item.key)}
                        className={cn(
                            'flex min-w-[7.5rem] items-center justify-between gap-3 rounded-xl border px-3 py-2 text-left text-xs transition-colors',
                            isActive
                                ? 'border-primary/40 bg-primary/10 ring-1 ring-primary/20'
                                : 'border-border/70 bg-background/70 hover:bg-muted/50',
                        )}
                    >
                        <span className="font-medium text-muted-foreground">
                            {item.label}
                        </span>
                        <span
                            className={cn('text-sm font-semibold', item.tone)}
                        >
                            {value}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
