import { Card, CardContent } from '@/components/ui/card';
import type {
    DocumentRequirementSummary,
    RequirementStatusFilter,
} from '@/features/organization/documents/shared/types';
import { cn } from '@/lib/utils';

const MISSING_CARD = {
    key: 'missing' as const,
    status: 'missing' as const,
    label: 'Missing',
    cardClass:
        'border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30',
    activeClass: 'border-violet-500/40 ring-1 ring-violet-500/25',
    valueClass: 'text-violet-400',
};

export function DocumentRequirementSummaryCards({
    summary,
    activeStatus,
    onSelect,
}: {
    summary: DocumentRequirementSummary;
    activeStatus: RequirementStatusFilter;
    onSelect: (status: RequirementStatusFilter) => void;
}) {
    const isActive = MISSING_CARD.status === activeStatus;

    return (
        <button
            type="button"
            onClick={() => onSelect(MISSING_CARD.status)}
            aria-pressed={isActive}
            className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <Card
                className={cn(
                    'glass-card transition-all duration-200',
                    MISSING_CARD.cardClass,
                    isActive && MISSING_CARD.activeClass,
                )}
            >
                <CardContent className="p-3 sm:p-4">
                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                        {MISSING_CARD.label}
                    </p>
                    <p
                        className={cn(
                            'mt-1 text-2xl font-bold tabular-nums',
                            MISSING_CARD.valueClass,
                        )}
                    >
                        {summary[MISSING_CARD.key]}
                    </p>
                </CardContent>
            </Card>
        </button>
    );
}
