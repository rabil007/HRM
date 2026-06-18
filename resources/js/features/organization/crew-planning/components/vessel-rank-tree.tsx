import { ChevronRight, Ship, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import type { TreeCrewMember, TreeRank, TreeVessel } from '../types';

type ManningFill = 'empty' | 'partial' | 'full' | 'over';

function crewInitials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

function manningFill(assigned: number, required: number): ManningFill {
    if (assigned === 0) {
        return 'empty';
    }

    if (assigned < required) {
        return 'partial';
    }

    if (assigned > required) {
        return 'over';
    }

    return 'full';
}

function ManningBadge({ assigned, required }: { assigned: number; required: number }): ReactElement {
    const fill = manningFill(assigned, required);

    return (
        <span
            className={cn(
                'ml-auto shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                fill === 'empty' && 'bg-muted/80 text-muted-foreground',
                fill === 'partial' &&
                    'bg-amber-500/15 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
                fill === 'full' &&
                    'bg-emerald-500/15 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
                fill === 'over' &&
                    'bg-[color-mix(in_oklch,var(--primary)_18%,var(--background))] text-primary',
            )}
            title={`${assigned} planned of ${required} required`}
        >
            {assigned}/{required}
        </span>
    );
}

function matchesSearch(text: string, query: string): boolean {
    return query === '' || text.toLowerCase().includes(query);
}

function rankMatchesSearch(rank: TreeRank, query: string): boolean {
    return (
        matchesSearch(rank.rank_name, query) ||
        rank.crew.some((member) => matchesSearch(member.employee_name, query))
    );
}

function vesselMatchesSearch(vessel: TreeVessel, query: string): boolean {
    return (
        matchesSearch(vessel.vessel_name, query) ||
        vessel.ranks.some((rank) => rankMatchesSearch(rank, query))
    );
}

function CrewMemberRow({
    member,
    query,
}: {
    member: TreeCrewMember;
    query: string;
}): ReactElement {
    const isMatch = query !== '' && matchesSearch(member.employee_name, query);
    const isVacant = member.employee_id === null;

    return (
        <div
            className={cn(
                'flex items-center gap-2 rounded-md py-1 pr-2 pl-1 text-xs',
                isMatch && 'bg-[color-mix(in_oklch,var(--primary)_10%,var(--background))]',
            )}
        >
            <span
                className={cn(
                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold',
                    isVacant
                        ? 'border border-dashed border-muted-foreground/30 text-muted-foreground/50'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {isVacant ? '—' : crewInitials(member.employee_name)}
            </span>
            <span
                className={cn(
                    'min-w-0 truncate',
                    isVacant ? 'italic text-muted-foreground/60' : 'text-foreground/85',
                    isMatch && 'font-medium text-foreground',
                )}
            >
                {member.employee_name}
            </span>
        </div>
    );
}

function RankNode({
    rank,
    rowKey,
    isSelected,
    search,
    onRowSelect,
    forceOpen,
}: {
    rank: TreeRank;
    rowKey: string;
    isSelected: boolean;
    search: string;
    onRowSelect: (rowKey: string) => void;
    forceOpen: boolean;
}): ReactElement | null {
    const lowerSearch = search.trim().toLowerCase();
    const [rankOpen, setRankOpen] = useState(rank.crew.length > 0);

    if (!rankMatchesSearch(rank, lowerSearch)) {
        return null;
    }

    const isExpanded = forceOpen || rankOpen;

    return (
        <Collapsible open={forceOpen || rankOpen} onOpenChange={setRankOpen}>
            <div
                className={cn(
                    'mx-2 mb-0.5 overflow-hidden rounded-lg border border-transparent transition-colors',
                    isSelected &&
                        'border-[color-mix(in_oklch,var(--primary)_25%,var(--border))] bg-[color-mix(in_oklch,var(--primary)_10%,var(--background))]',
                    !isSelected && 'hover:bg-muted/40',
                )}
            >
                <div className="flex min-w-0 items-stretch">
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex w-7 shrink-0 items-center justify-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                            aria-label={isExpanded ? `Collapse ${rank.rank_name}` : `Expand ${rank.rank_name}`}
                        >
                            <ChevronRight
                                className={cn(
                                    'h-3.5 w-3.5 transition-transform duration-200',
                                    isExpanded && 'rotate-90',
                                )}
                            />
                        </button>
                    </CollapsibleTrigger>
                    <button
                        type="button"
                        className="flex min-w-0 flex-1 items-center gap-2 py-2 pr-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                        onClick={() => onRowSelect(rowKey)}
                    >
                        <span
                            className={cn(
                                'truncate text-xs font-medium',
                                isSelected ? 'text-foreground' : 'text-foreground/90',
                            )}
                        >
                            {rank.rank_name}
                        </span>
                        <ManningBadge assigned={rank.crew.length} required={rank.required_count} />
                    </button>
                </div>

                <CollapsibleContent>
                    <div className="space-y-0.5 border-t border-border/40 px-2 py-1.5 pl-7">
                        {rank.crew.length === 0 ? (
                            <div className="flex items-center gap-2 py-1 text-[11px] text-muted-foreground/55">
                                <UserRound className="h-3 w-3 shrink-0" />
                                <span>No crew planned in this range</span>
                            </div>
                        ) : (
                            rank.crew.map((member, index) => (
                                <CrewMemberRow
                                    key={
                                        member.employee_id != null
                                            ? `emp-${member.employee_id}`
                                            : `vacant-${index}`
                                    }
                                    member={member}
                                    query={lowerSearch}
                                />
                            ))
                        )}
                    </div>
                </CollapsibleContent>
            </div>
        </Collapsible>
    );
}

function VesselNode({
    vessel,
    search,
    selectedRowKey,
    onRowSelect,
    forceOpen,
}: {
    vessel: TreeVessel;
    search: string;
    selectedRowKey: string | null;
    onRowSelect: (rowKey: string) => void;
    forceOpen: boolean;
}): ReactElement | null {
    const lowerSearch = search.trim().toLowerCase();
    const [open, setOpen] = useState(true);
    const plannedCount = vessel.ranks.reduce((total, rank) => total + rank.crew.length, 0);

    if (!vesselMatchesSearch(vessel, lowerSearch)) {
        return null;
    }

    return (
        <Collapsible open={forceOpen || open} onOpenChange={setOpen} className="border-b border-border/50 last:border-b-0">
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2.5 text-left transition-colors hover:bg-muted/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                >
                    <ChevronRight
                        className={cn(
                            'h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform duration-200',
                            open && 'rotate-90',
                        )}
                    />
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-muted/60 text-muted-foreground">
                        <Ship className="h-3.5 w-3.5" />
                    </span>
                    <span className="min-w-0 flex-1 truncate text-sm font-semibold tracking-tight">
                        {vessel.vessel_name}
                    </span>
                    <span className="shrink-0 rounded-md bg-muted/70 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-muted-foreground">
                        {plannedCount} planned
                    </span>
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pb-2 pt-0.5">
                {vessel.ranks.map((rank) => {
                    const rowKey = `vessel:${vessel.vessel_id}|rank:${rank.rank_id}`;

                    return (
                        <RankNode
                            key={rowKey}
                            rank={rank}
                            rowKey={rowKey}
                            isSelected={selectedRowKey === rowKey}
                            search={search}
                            onRowSelect={onRowSelect}
                            forceOpen={lowerSearch !== '' && rankMatchesSearch(rank, lowerSearch)}
                        />
                    );
                })}
            </CollapsibleContent>
        </Collapsible>
    );
}

export function VesselRankTree({
    tree,
    search,
    selectedRowKey,
    onRowSelect,
}: {
    tree: TreeVessel[];
    search: string;
    selectedRowKey: string | null;
    onRowSelect: (rowKey: string) => void;
}): ReactElement {
    const lowerSearch = search.trim().toLowerCase();
    const visibleVessels = tree.filter((vessel) => vesselMatchesSearch(vessel, lowerSearch));

    if (tree.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground">
                    <Ship className="h-5 w-5" />
                </span>
                <p className="text-sm font-medium text-foreground/90">No vessels configured</p>
                <p className="max-w-[200px] text-xs leading-relaxed text-muted-foreground/70">
                    Add vessel manning requirements to start planning crew on the timeline.
                </p>
            </div>
        );
    }

    if (visibleVessels.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
                <p className="text-sm font-medium text-foreground/90">No matches</p>
                <p className="text-xs text-muted-foreground/70">Try a different vessel, rank, or crew name.</p>
            </div>
        );
    }

    return (
        <div className="flex flex-col py-1">
            {visibleVessels.map((vessel) => (
                <VesselNode
                    key={vessel.vessel_id}
                    vessel={vessel}
                    search={search}
                    selectedRowKey={selectedRowKey}
                    onRowSelect={onRowSelect}
                    forceOpen={lowerSearch !== '' && vesselMatchesSearch(vessel, lowerSearch)}
                />
            ))}
        </div>
    );
}
