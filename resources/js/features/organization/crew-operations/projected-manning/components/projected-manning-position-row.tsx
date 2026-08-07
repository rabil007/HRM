import { Link } from '@inertiajs/react';
import { CalendarPlus, ChevronDown } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import {
    hasProjectedManningGap,
    projectedManningStatusVariant,
} from '../status';
import type { ProjectedManningItem } from '../types';

function Metric({
    label,
    value,
    emphasize,
}: {
    label: string;
    value: string | number;
    emphasize?: 'actual' | 'projected' | 'gap';
}): ReactElement {
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-bold tracking-[0.14em] text-muted-foreground/65 uppercase">
                {label}
            </p>
            <p
                className={cn(
                    'mt-0.5 text-sm font-semibold tabular-nums',
                    emphasize === 'actual' && 'text-foreground',
                    emphasize === 'projected' &&
                        'text-sky-700 dark:text-sky-300',
                    emphasize === 'gap' &&
                        Number(value) > 0 &&
                        'text-destructive',
                )}
            >
                {value}
            </p>
        </div>
    );
}

export function ProjectedManningPositionRow({
    item,
    from,
    to,
    canPlanCrew,
}: {
    item: ProjectedManningItem;
    from: string;
    to: string;
    canPlanCrew: boolean;
}): ReactElement {
    const showPlanCrew = canPlanCrew && hasProjectedManningGap(item.status);

    const gapPeriods = item.periods.filter((period) => period.gap > 0);
    const excessPeriods = item.periods.filter((period) => period.excess > 0);
    const joins = item.events.filter((event) => event.type === 'join');
    const signOffs = item.events.filter((event) => event.type === 'signoff');

    const planHref = crewPlanningIndex.url({
        query: {
            open_create: 1,
            vessel_id: item.vessel_id,
            rank_id: item.rank_id,
            from,
            to,
            ...(item.next_gap_date
                ? { planned_join_date: item.next_gap_date }
                : {}),
        },
    });

    return (
        <Collapsible className="overflow-hidden rounded-2xl border glass-card border-border/70 bg-card/70 dark:border-white/5">
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:gap-4">
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate text-sm font-semibold">
                            {item.vessel_name}
                        </p>
                        <Badge variant="outline" className="font-medium">
                            {item.rank_name}
                        </Badge>
                        <Badge
                            variant={projectedManningStatusVariant(item.status)}
                        >
                            {item.status_label}
                        </Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Required {item.required_count}
                        {item.next_gap_date
                            ? ` · Next gap ${formatDisplayDate(item.next_gap_date)}`
                            : ''}
                        {item.has_open_ended_onboard
                            ? ' · Open-ended projected coverage'
                            : ''}
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 lg:gap-4">
                    <Metric
                        label="Actual onboard"
                        value={item.actual_onboard_at_start}
                        emphasize="actual"
                    />
                    <Metric
                        label="Projected at start"
                        value={item.projected_count_at_start}
                        emphasize="projected"
                    />
                    <Metric
                        label="Minimum projected"
                        value={item.minimum_projected_count}
                        emphasize="projected"
                    />
                    <Metric
                        label="Current gap"
                        value={item.current_gap}
                        emphasize="gap"
                    />
                    <Metric
                        label="Maximum gap"
                        value={item.maximum_gap}
                        emphasize="gap"
                    />
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {showPlanCrew ? (
                        <Button
                            asChild
                            size="sm"
                            variant="outline"
                            className="rounded-lg"
                        >
                            <Link href={planHref}>
                                <CalendarPlus className="size-3.5" />
                                Plan Crew
                            </Link>
                        </Button>
                    ) : null}
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="rounded-lg data-[state=open]:bg-muted/50 [&[data-state=open]>svg]:rotate-180"
                        >
                            Details
                            <ChevronDown className="size-3.5 transition-transform" />
                        </Button>
                    </CollapsibleTrigger>
                </div>
            </div>

            <CollapsibleContent>
                <div className="space-y-4 border-t border-border/60 bg-muted/15 px-4 py-4 dark:border-white/5 dark:bg-white/2">
                    <div className="grid gap-4 lg:grid-cols-3">
                        <DetailSection title="Joins">
                            {joins.length === 0 ? (
                                <EmptyDetail text="No joins in this horizon." />
                            ) : (
                                <ul className="space-y-1.5">
                                    {joins.map((event, index) => (
                                        <li
                                            key={`join-${event.date}-${index}`}
                                            className="text-xs text-muted-foreground"
                                        >
                                            <span className="font-medium text-foreground">
                                                {formatDisplayDate(event.date)}
                                            </span>
                                            {event.is_relief
                                                ? ' · Relief join'
                                                : ' · Join'}
                                            {event.crew_assignment_id
                                                ? ` · Assignment #${event.crew_assignment_id}`
                                                : event.crew_planning_assignment_id
                                                  ? ` · Planning #${event.crew_planning_assignment_id}`
                                                  : ''}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </DetailSection>

                        <DetailSection title="Sign-offs">
                            {signOffs.length === 0 ? (
                                <EmptyDetail text="No sign-offs in this horizon." />
                            ) : (
                                <ul className="space-y-1.5">
                                    {signOffs.map((event, index) => (
                                        <li
                                            key={`signoff-${event.date}-${index}`}
                                            className="text-xs text-muted-foreground"
                                        >
                                            <span className="font-medium text-foreground">
                                                {formatDisplayDate(event.date)}
                                            </span>
                                            {' · Forecast sign-off'}
                                            {event.crew_assignment_id
                                                ? ` · Assignment #${event.crew_assignment_id}`
                                                : ''}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </DetailSection>

                        <DetailSection title="Gap / excess periods">
                            {gapPeriods.length === 0 &&
                            excessPeriods.length === 0 ? (
                                <EmptyDetail text="No gap or excess periods." />
                            ) : (
                                <ul className="space-y-1.5">
                                    {gapPeriods.map((period) => (
                                        <li
                                            key={`gap-${period.from}-${period.to}`}
                                            className="text-xs text-muted-foreground"
                                        >
                                            <span className="font-medium text-destructive">
                                                Gap {period.gap}
                                            </span>
                                            {` · ${formatDisplayDate(period.from)} → ${formatDisplayDate(period.to)}`}
                                            {` · projected ${period.projected_count}`}
                                        </li>
                                    ))}
                                    {excessPeriods.map((period) => (
                                        <li
                                            key={`excess-${period.from}-${period.to}`}
                                            className="text-xs text-muted-foreground"
                                        >
                                            <span className="font-medium text-warning">
                                                Excess {period.excess}
                                            </span>
                                            {` · ${formatDisplayDate(period.from)} → ${formatDisplayDate(period.to)}`}
                                            {` · projected ${period.projected_count}`}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </DetailSection>
                    </div>

                    <DetailSection title="Projection periods">
                        {item.periods.length === 0 ? (
                            <EmptyDetail text="No projection periods." />
                        ) : (
                            <div className="overflow-x-auto rounded-xl border border-border/60 dark:border-white/5">
                                <table className="min-w-full text-left text-xs">
                                    <thead className="bg-muted/40 text-[10px] tracking-wider text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-3 py-2 font-semibold">
                                                From
                                            </th>
                                            <th className="px-3 py-2 font-semibold">
                                                To
                                            </th>
                                            <th className="px-3 py-2 font-semibold">
                                                Projected
                                            </th>
                                            <th className="px-3 py-2 font-semibold">
                                                Gap
                                            </th>
                                            <th className="px-3 py-2 font-semibold">
                                                Excess
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {item.periods.map((period) => (
                                            <tr
                                                key={`${period.from}-${period.to}`}
                                                className="border-t border-border/50 dark:border-white/5"
                                            >
                                                <td className="px-3 py-2 tabular-nums">
                                                    {formatDisplayDate(
                                                        period.from,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 tabular-nums">
                                                    {formatDisplayDate(
                                                        period.to,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-sky-700 tabular-nums dark:text-sky-300">
                                                    {period.projected_count}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'px-3 py-2 tabular-nums',
                                                        period.gap > 0 &&
                                                            'text-destructive',
                                                    )}
                                                >
                                                    {period.gap}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'px-3 py-2 tabular-nums',
                                                        period.excess > 0 &&
                                                            'text-warning',
                                                    )}
                                                >
                                                    {period.excess}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </DetailSection>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function DetailSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}): ReactElement {
    return (
        <div className="space-y-2">
            <h3 className="text-[11px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                {title}
            </h3>
            {children}
        </div>
    );
}

function EmptyDetail({ text }: { text: string }): ReactElement {
    return <p className="text-xs text-muted-foreground/70">{text}</p>;
}
