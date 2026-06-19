import { router } from '@inertiajs/react';
import {
    CalendarCheck,
    ChevronLeft,
    ChevronRight,
    Plus,
    Search,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import type { ReactElement, RefObject } from 'react';
import { index as planningIndex } from '@/actions/App/Http/Controllers/Organization/CrewPlanningController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { formatIsoDateLocal } from '../lib/planning-gantt-math';
import { useZoom  } from '../lib/zoom-context';
import type {ZoomLevel} from '../lib/zoom-context';
import type { PlanningFilters, PlanningOption, PlanningPagePermissions } from '../types';

type Props = {
    filters: PlanningFilters;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    onSearchChange: (value: string) => void;
    searchInput: string;
    can: PlanningPagePermissions;
    onAssign: () => void;
    ganttRef: RefObject<HTMLDivElement | null>;
    today: string;
};

function formatMonthLabel(dateStr: string): string {
    const d = new Date(`${dateStr}T00:00:00`);

    return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

function addMonths(dateStr: string, delta: number): string {
    const d = new Date(`${dateStr}T00:00:00`);
    d.setMonth(d.getMonth() + delta);
    d.setDate(1);

    return formatIsoDateLocal(d);
}

function endOfMonth(dateStr: string): string {
    const d = new Date(`${dateStr}T00:00:00`);
    const end = new Date(d.getFullYear(), d.getMonth() + 1, 0);

    return formatIsoDateLocal(end);
}

function visit(params: Partial<PlanningFilters & { from: string; to: string }>): void {
    const clean: Record<string, string> = {};
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') {
            clean[k] = String(v);
        }
    });
    router.get(planningIndex.url(), clean, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const ZOOM_LABELS: Record<ZoomLevel, string> = {
    compact: 'Compact',
    normal: 'Normal',
    wide: 'Wide',
};

export function PlanningToolbar({
    filters,
    vessels,
    ranks,
    onSearchChange,
    searchInput,
    can,
    onAssign,
    ganttRef,
    today,
}: Props): ReactElement {
    const { zoom, zoomIn, zoomOut } = useZoom();
    const { from, to } = filters;

    const todayIsInRange = today >= from && today <= to;

    const handleJumpToToday = (): void => {
        if (!todayIsInRange) {
            // Drop from/to so the server restores the default range for today's month
            const { vessel_id, rank_id, search } = filters;
            const clean: Record<string, string> = {};
            Object.entries({ vessel_id, rank_id, search }).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== '') {
                    clean[k] = String(v);
                }
            });
            router.get(planningIndex.url(), clean, {
                preserveState: false,
                replace: true,
            });

            return;
        }

        // Today is visible — just scroll to it
        const el = ganttRef.current?.querySelector('[data-today-col]') as HTMLElement | null;

        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    };

    const fromLabel = formatMonthLabel(from);
    const toLabel = formatMonthLabel(to);
    const rangeLabel = fromLabel === toLabel ? fromLabel : `${fromLabel} – ${toLabel}`;

    const handlePrev = (): void => {
        const newFrom = addMonths(from, -1);
        const newTo = endOfMonth(addMonths(to, -1));
        visit({ ...filters, from: newFrom, to: newTo });
    };

    const handleNext = (): void => {
        const newFrom = addMonths(from, 1);
        const newTo = endOfMonth(addMonths(to, 1));
        visit({ ...filters, from: newFrom, to: newTo });
    };

    const handleVesselChange = (value: string): void => {
        visit({ ...filters, vessel_id: value === '' ? null : (Number(value) as number | null) });
    };

    const handleRankChange = (value: string): void => {
        visit({ ...filters, rank_id: value === '' ? null : (Number(value) as number | null) });
    };

    return (
        <div className="flex flex-wrap items-center gap-2 border-b bg-background/95 px-4 py-2.5 backdrop-blur-sm">
            {/* Filters group */}
            <div className="flex items-center gap-2">
                <AppSelect
                    value={filters.vessel_id !== null ? String(filters.vessel_id) : ''}
                    onValueChange={handleVesselChange}
                    placeholder="All vessels"
                    searchPlaceholder="Search vessels..."
                    size="sm"
                    className="w-44"
                >
                    <AppSelectItem value="">All vessels</AppSelectItem>
                    {vessels.map((v) => (
                        <AppSelectItem key={v.id} value={String(v.id)}>
                            {v.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>

                <AppSelect
                    value={filters.rank_id !== null ? String(filters.rank_id) : ''}
                    onValueChange={handleRankChange}
                    placeholder="All ranks"
                    searchPlaceholder="Search ranks..."
                    size="sm"
                    className="w-40"
                >
                    <AppSelectItem value="">All ranks</AppSelectItem>
                    {ranks.map((r) => (
                        <AppSelectItem key={r.id} value={String(r.id)}>
                            {r.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            {/* Divider */}
            <div className="h-5 w-px bg-border/60" />

            {/* Date navigator */}
            <div className="flex items-center gap-0.5 rounded-md border bg-muted/30 p-0.5">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 rounded"
                    onClick={handlePrev}
                    aria-label="Previous month"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                </Button>
                <span className="min-w-44 px-1 text-center text-sm font-medium tabular-nums">
                    {rangeLabel}
                </span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 rounded"
                    onClick={handleNext}
                    aria-label="Next month"
                >
                    <ChevronRight className="h-3.5 w-3.5" />
                </Button>
            </div>

            {/* Search */}
            <div className="relative flex items-center">
                <Search className="absolute left-2.5 h-3.5 w-3.5 text-muted-foreground/60" />
                <Input
                    className="h-8 w-48 rounded-md pl-8 pr-7 text-sm"
                    placeholder="Find crew by name…"
                    value={searchInput}
                    onChange={(e) => onSearchChange(e.target.value)}
                />
                {searchInput !== '' ? (
                    <button
                        className="absolute right-2 text-muted-foreground transition-colors hover:text-foreground"
                        onClick={() => onSearchChange('')}
                        aria-label="Clear search"
                    >
                        <X className="h-3.5 w-3.5" />
                    </button>
                ) : null}
            </div>

            {/* Zoom controls */}
            <div className="flex items-center gap-0.5 rounded-md border bg-muted/30 p-0.5">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 rounded"
                    onClick={zoomOut}
                    aria-label="Zoom out"
                    disabled={zoom === 'compact'}
                >
                    <ZoomOut className="h-3.5 w-3.5" />
                </Button>
                <span className="min-w-16 px-1 text-center text-[11px] font-medium text-muted-foreground">
                    {ZOOM_LABELS[zoom]}
                </span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 rounded"
                    onClick={zoomIn}
                    aria-label="Zoom in"
                    disabled={zoom === 'wide'}
                >
                    <ZoomIn className="h-3.5 w-3.5" />
                </Button>
            </div>

            {/* Jump to today */}
            <Button
                variant="outline"
                size="sm"
                className={cn(
                    'h-8 gap-1.5 px-3 text-xs',
                    todayIsInRange
                        ? 'border-red-300 text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950/40'
                        : 'border-amber-300 text-amber-600 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400 dark:hover:bg-amber-950/40',
                )}
                onClick={handleJumpToToday}
                aria-label="Jump to today"
                title={todayIsInRange ? 'Scroll to today' : 'Go to current month'}
            >
                <CalendarCheck className="h-3.5 w-3.5" />
                {todayIsInRange ? 'Today' : 'Go to Today'}
            </Button>

            {/* Actions — pushed right */}
            <div className="ml-auto flex items-center gap-2">
                {can.create ? (
                    <Button size="sm" className="h-8 gap-1.5 px-3" onClick={onAssign}>
                        <Plus className="h-3.5 w-3.5" />
                        Assign
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
