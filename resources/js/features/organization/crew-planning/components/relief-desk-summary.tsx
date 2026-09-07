import { RELIEF_DESK_QUICK_VIEWS } from '@/features/organization/crew-planning/lib/relief-desk-query';
import type {
    ReliefDeskFocus,
    ReliefDeskSummary,
} from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

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
            {RELIEF_DESK_QUICK_VIEWS.map((item) => {
                const isActive = activeFocus === item.key;
                const value = item.getValue(summary);

                return (
                    <button
                        key={item.key}
                        type="button"
                        aria-pressed={isActive}
                        onClick={() => onSelect(isActive ? '' : item.key)}
                        className={cn(
                            'flex min-w-[9rem] items-center justify-between gap-3 rounded-xl border px-3 py-2 text-left text-xs transition-colors',
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
