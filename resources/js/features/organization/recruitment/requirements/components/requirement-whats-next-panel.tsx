import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    RequirementDetail,
    RequirementIndexRow,
} from '@/types/recruitment';
import { resolveRequirementWhatsNext } from '../lib/requirement-whats-next';
import type { RequirementWhatsNextAction } from '../lib/requirement-whats-next';
import { RequirementStatusBadge } from './requirement-status-badge';

type Props = {
    requirement: RequirementIndexRow | RequirementDetail;
    onAction: (action: Exclude<RequirementWhatsNextAction, null>) => void;
    processing?: boolean;
    className?: string;
};

const TONE_STYLES = {
    primary: 'border-primary/25 bg-primary/5',
    warning: 'border-amber-500/25 bg-amber-500/5',
    success: 'border-emerald-500/25 bg-emerald-500/5',
    neutral: 'border-border/70 bg-muted/20',
} as const;

export function RequirementWhatsNextPanel({
    requirement,
    onAction,
    processing = false,
    className,
}: Props) {
    const next = resolveRequirementWhatsNext(requirement);

    return (
        <Card
            className={cn(
                'overflow-hidden border-border/70 shadow-xs',
                TONE_STYLES[next.tone],
                className,
            )}
        >
            <CardHeader className="space-y-3 pb-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-base font-semibold">
                        What’s next?
                    </CardTitle>
                    <RequirementStatusBadge
                        status={requirement.status}
                        label={requirement.status_label}
                    />
                </div>
                <div>
                    <p className="text-sm font-medium text-foreground">
                        {next.title}
                    </p>
                    <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                        {next.description}
                    </p>
                </div>
            </CardHeader>
            {next.action && next.ctaLabel ? (
                <CardContent className="pt-0">
                    <Button
                        type="button"
                        className="h-10 w-full gap-2"
                        disabled={processing}
                        onClick={() => onAction(next.action!)}
                    >
                        {next.ctaLabel}
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Button>
                </CardContent>
            ) : null}
        </Card>
    );
}
