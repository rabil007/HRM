import { CREW_READINESS_QUICK_VIEWS } from '@/features/organization/crew-readiness/lib/crew-readiness-query';
import type {
    CrewReadinessFocus,
    CrewReadinessSummary,
} from '@/features/organization/crew-readiness/types';
import { cn } from '@/lib/utils';

export function CrewReadinessSummaryStrip({
    summary,
    activeFocus,
    onSelect,
}: {
    summary: CrewReadinessSummary;
    activeFocus: CrewReadinessFocus;
    onSelect: (focus: CrewReadinessFocus) => void;
}) {
    return (
        <div
            className="flex flex-wrap gap-2.5"
            role="group"
            aria-label="Crew readiness quick views"
        >
            {CREW_READINESS_QUICK_VIEWS.map((item) => {
                const isActive = activeFocus === item.key;
                const value = item.getValue(summary);

                return (
                    <button
                        key={item.key || 'all'}
                        type="button"
                        aria-pressed={isActive}
                        onClick={() => onSelect(isActive ? '' : item.key)}
                        className={cn(
                            'flex min-w-[9.5rem] items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5 text-left text-xs shadow-xs transition-all',
                            isActive
                                ? 'border-primary/50 bg-primary/10 font-medium ring-2 ring-primary/25'
                                : 'border-border/70 bg-card/80 hover:border-border hover:bg-muted/60',
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
