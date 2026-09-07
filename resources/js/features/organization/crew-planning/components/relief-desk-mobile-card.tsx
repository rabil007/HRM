import { MobileRecordCard } from '@/components/mobile-record-list';
import { Badge } from '@/components/ui/badge';
import { reliefDeskMobileCardModel } from '@/features/organization/crew-planning/lib/relief-desk-mobile-card';
import type { ReliefDeskRow } from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

const RISK_STYLES: Record<string, string> = {
    none: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
    warning:
        'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    critical: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
};

export function ReliefDeskMobileCard({ row }: { row: ReliefDeskRow }) {
    const model = reliefDeskMobileCardModel(row);

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.subtitle}
            href={row.source_href ?? undefined}
            meta={[
                `Sign-off: ${model.signoff}`,
                `Relief: ${model.relief}${model.reliefPhase ? ` · ${model.reliefPhase}` : ''}`,
                model.readiness ? `Readiness: ${model.readiness}` : null,
            ]}
            status={
                <Badge
                    variant="outline"
                    className={cn(
                        'w-fit font-medium',
                        RISK_STYLES[row.relief_risk] ?? RISK_STYLES.none,
                    )}
                >
                    {model.risk}
                </Badge>
            }
            primaryAction={
                model.actionHref && model.actionLabel
                    ? {
                          label: model.actionLabel,
                          href: model.actionHref,
                      }
                    : undefined
            }
        />
    );
}
