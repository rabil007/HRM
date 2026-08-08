import type { ReactElement } from 'react';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    deployedBarSurfaceClass,
    plannedBarSurfaceClass,
    plannedReliefBarSurfaceClass,
} from '../lib/assignment-bar-styles';

const LEGEND_ITEMS = [
    {
        label: 'Crew Assigned',
        description: 'A crew assignment already exists for this person.',
        surfaceClass: deployedBarSurfaceClass,
        labelClass: 'text-emerald-700 dark:text-emerald-300',
        swatchRingClass: 'ring-emerald-500/45 dark:ring-emerald-400/55',
    },
    {
        label: 'Relief Planned',
        description: 'Replacement crew is planned to take over this position.',
        surfaceClass: plannedReliefBarSurfaceClass,
        labelClass: 'text-sky-700 dark:text-sky-300',
        swatchRingClass: 'ring-sky-500/45 dark:ring-sky-400/55',
    },
    {
        label: 'Planned Crew',
        description: 'Crew is planned, but no assignment has been created yet.',
        surfaceClass: plannedBarSurfaceClass,
        labelClass: 'text-indigo-700 dark:text-indigo-300',
        swatchRingClass: 'ring-indigo-500/45 dark:ring-indigo-400/55',
    },
] as const;

const PROJECTION_LEGEND_ITEMS = [
    {
        label: 'Current Manning Shortfall',
        description: 'The vessel is short of the required crew right now.',
        surfaceClass: 'bg-destructive/20 dark:bg-destructive/25',
        labelClass: 'text-destructive',
        swatchRingClass: 'ring-destructive/40',
    },
    {
        label: 'Future Manning Shortfall',
        description:
            'This position will become short after planned sign-off unless relief is arranged.',
        surfaceClass: 'bg-destructive/20 dark:bg-destructive/25',
        labelClass: 'text-destructive',
        swatchRingClass: 'ring-destructive/40',
    },
    {
        label: 'Relief Overlap',
        description:
            'More crew than required are planned at the same time, usually for handover.',
        surfaceClass: 'bg-amber-500/20 dark:bg-amber-400/25',
        labelClass: 'text-amber-700 dark:text-amber-300',
        swatchRingClass: 'ring-amber-500/40',
    },
] as const;

type Props = {
    canProjection?: boolean;
    showCoverage?: boolean;
    onShowCoverageChange?: (value: boolean) => void;
};

export function PlanningLegend({
    canProjection = false,
    showCoverage = false,
    onShowCoverageChange,
}: Props): ReactElement {
    const items = canProjection
        ? [...LEGEND_ITEMS, ...PROJECTION_LEGEND_ITEMS]
        : LEGEND_ITEMS;

    return (
        <div
            className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b bg-muted/20 px-4 py-1.5 dark:bg-muted/10"
            aria-label="Timeline legend"
        >
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="cursor-help text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Legend
                    </span>
                </TooltipTrigger>
                <TooltipContent
                    side="bottom"
                    align="start"
                    className="max-w-xs"
                >
                    Timeline bar kinds show crew assigned, relief planned, and
                    planned crew.
                    {canProjection
                        ? ' Projected shortfall and overlap bands come from Vessel Manning coverage.'
                        : ''}
                </TooltipContent>
            </Tooltip>

            <div
                className="flex flex-wrap items-center gap-x-3 gap-y-1"
                role="list"
            >
                {items.map((item) => (
                    <Tooltip key={item.label}>
                        <TooltipTrigger asChild>
                            <div
                                role="listitem"
                                className="flex cursor-help items-center gap-1.5 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <span
                                    className={cn(
                                        'inline-block h-2.5 w-7 shrink-0 rounded-sm ring-1 ring-offset-1 ring-offset-background',
                                        item.surfaceClass,
                                        item.swatchRingClass,
                                    )}
                                    aria-hidden
                                />
                                <span
                                    className={cn(
                                        'text-xs font-medium',
                                        item.labelClass,
                                    )}
                                >
                                    {item.label}
                                </span>
                            </div>
                        </TooltipTrigger>
                        <TooltipContent
                            side="bottom"
                            align="start"
                            className="max-w-xs"
                        >
                            <p className="font-semibold">{item.label}</p>
                            <p className="mt-0.5 text-primary-foreground/90">
                                {item.description}
                            </p>
                        </TooltipContent>
                    </Tooltip>
                ))}
            </div>

            {canProjection && onShowCoverageChange ? (
                <div className="ml-auto flex items-center gap-2">
                    <Switch
                        id="planning-show-coverage"
                        checked={showCoverage}
                        onCheckedChange={onShowCoverageChange}
                    />
                    <Label
                        htmlFor="planning-show-coverage"
                        className="cursor-pointer text-xs font-medium text-muted-foreground"
                    >
                        Show coverage
                    </Label>
                </div>
            ) : null}
        </div>
    );
}
