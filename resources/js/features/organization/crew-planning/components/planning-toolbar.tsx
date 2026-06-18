import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus, Search, Settings, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { index as planningIndex } from '@/actions/App/Http/Controllers/Organization/CrewPlanningController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { PlanningFilters, PlanningOption, PlanningPagePermissions } from '../types';

type Props = {
    filters: PlanningFilters;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    onSearchChange: (value: string) => void;
    searchInput: string;
    can: PlanningPagePermissions;
    onAssign: () => void;
    onOpenSettings?: () => void;
};

function formatMonthLabel(dateStr: string): string {
    const d = new Date(`${dateStr}T00:00:00`);
    return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

function addMonths(dateStr: string, delta: number): string {
    const d = new Date(`${dateStr}T00:00:00`);
    d.setMonth(d.getMonth() + delta);
    d.setDate(1);
    return d.toISOString().split('T')[0];
}

function endOfMonth(dateStr: string): string {
    const d = new Date(`${dateStr}T00:00:00`);
    const end = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    return end.toISOString().split('T')[0];
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

export function PlanningToolbar({
    filters,
    vessels,
    ranks,
    onSearchChange,
    searchInput,
    can,
    onAssign,
    onOpenSettings,
}: Props): ReactElement {
    const { from, to } = filters;

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
        <div className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
            <AppSelect
                value={filters.vessel_id !== null ? String(filters.vessel_id) : ''}
                onValueChange={handleVesselChange}
                placeholder="All vessels"
                searchPlaceholder="Search vessels..."
                size="sm"
                className="w-48"
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
                className="w-44"
            >
                <AppSelectItem value="">All ranks</AppSelectItem>
                {ranks.map((r) => (
                    <AppSelectItem key={r.id} value={String(r.id)}>
                        {r.name}
                    </AppSelectItem>
                ))}
            </AppSelect>

            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    onClick={handlePrev}
                    aria-label="Previous month"
                >
                    <ChevronLeft className="h-4 w-4" />
                </Button>
                <span className="min-w-48 text-center text-sm font-medium">{rangeLabel}</span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8"
                    onClick={handleNext}
                    aria-label="Next month"
                >
                    <ChevronRight className="h-4 w-4" />
                </Button>
            </div>

            <div className="relative flex items-center">
                <Search className="absolute left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                <Input
                    className="h-8 w-52 pl-8 pr-7 text-sm"
                    placeholder="Find crew by name…"
                    value={searchInput}
                    onChange={(e) => onSearchChange(e.target.value)}
                />
                {searchInput !== '' ? (
                    <button
                        className="absolute right-2 text-muted-foreground hover:text-foreground"
                        onClick={() => onSearchChange('')}
                        aria-label="Clear search"
                    >
                        <X className="h-3.5 w-3.5" />
                    </button>
                ) : null}
            </div>

            {can.create ? (
                <Button size="sm" className="ml-auto h-8 gap-1.5" onClick={onAssign}>
                    <Plus className="h-3.5 w-3.5" />
                    Assign
                </Button>
            ) : null}

            {can.update ? (
                <Button
                    variant="outline"
                    size="icon"
                    className={cn('h-8 w-8', !can.create && 'ml-auto')}
                    onClick={onOpenSettings}
                    aria-label="Planning settings"
                >
                    <Settings className="h-3.5 w-3.5" />
                </Button>
            ) : null}
        </div>
    );
}
