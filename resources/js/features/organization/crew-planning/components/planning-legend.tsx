import type { ReactElement } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    deployedBarSurfaceClass,
    plannedReliefBarSurfaceClass,
} from '../lib/assignment-bar-styles';

const LEGEND_ITEMS = [
    {
        label: 'Assigned',
        description:
            'Crew currently on the vessel — synced automatically from Current Crew.',
        surfaceClass: deployedBarSurfaceClass,
        labelClass: 'text-emerald-700 dark:text-emerald-300',
        swatchRingClass: 'ring-emerald-500/45 dark:ring-emerald-400/55',
    },
    {
        label: 'Planned relief',
        description:
            'Successor crew you plan here to replace assigned crew after they leave.',
        surfaceClass: plannedReliefBarSurfaceClass,
        labelClass: 'text-sky-700 dark:text-sky-300',
        swatchRingClass: 'ring-sky-500/45 dark:ring-sky-400/55',
    },
] as const;

export function PlanningLegend(): ReactElement {
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
                    Timeline bar colors show whether crew is currently deployed
                    on a vessel or planned as relief for a future handover.
                </TooltipContent>
            </Tooltip>

            <div
                className="flex flex-wrap items-center gap-x-3 gap-y-1"
                role="list"
            >
                {LEGEND_ITEMS.map((item) => (
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
        </div>
    );
}
