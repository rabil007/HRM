import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    keyOperationalDateKindLabel,
    operatorAttentionBadgeVariant,
} from '@/features/organization/crew/lib/operator-presentation';
import type {
    CrewKeyOperationalDate,
    CrewOperatorAttention,
    CrewOperatorStage,
} from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function CrewOperatorStageFact({
    stage,
}: {
    stage: CrewOperatorStage;
}): ReactElement {
    return (
        <div className="min-w-0">
            <p className="text-sm font-semibold text-foreground">
                {stage.label}
            </p>
            {stage.secondary ? (
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                    {stage.secondary}
                </p>
            ) : null}
        </div>
    );
}

export function CrewKeyOperationalDateFact({
    value,
}: {
    value: CrewKeyOperationalDate;
}): ReactElement {
    const missing = value.date == null;
    const shown = missing
        ? value.label === 'Planned Sign-Off'
            ? 'Sign-Off Not Planned'
            : 'Not recorded'
        : formatDisplayDate(value.date);

    return (
        <div className="min-w-0">
            <p className="text-[11px] text-muted-foreground">
                {value.label}
                <span className="ml-1 tracking-wide uppercase">
                    {`· ${keyOperationalDateKindLabel(value.kind)}`}
                </span>
            </p>
            <p
                className={cn(
                    'font-medium',
                    missing ? 'text-muted-foreground' : 'text-foreground',
                )}
            >
                {shown}
            </p>
        </div>
    );
}

export function CrewOperatorAttentionFact({
    attention,
}: {
    attention: CrewOperatorAttention;
}): ReactElement {
    const clear = attention.tone === 'clear' || attention.items.length === 0;
    const variant = clear
        ? 'outline'
        : operatorAttentionBadgeVariant(attention.tone);
    const title = attention.items
        .map((item) => `${item.label}: ${item.detail}`)
        .join('\n');

    return (
        <div className="flex flex-wrap items-center gap-1.5" title={title}>
            <Badge
                variant={variant}
                className={cn(clear && 'text-muted-foreground')}
            >
                {attention.label}
            </Badge>
            {attention.extra_count > 0 ? (
                <span className="text-[11px] text-muted-foreground">
                    +{attention.extra_count}
                </span>
            ) : null}
        </div>
    );
}
