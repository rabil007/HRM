import { Anchor, ChevronRight, Ship, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { barAvatarClass } from '../lib/assignment-bar-styles';
import type { TreeCrewMember, TreeRank, TreeVessel } from '../types';

function crewInitials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
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

function crewNameClass(member: TreeCrewMember): string {
    if (member.employee_id === null) {
        return 'italic text-muted-foreground/60';
    }

    return member.is_deployed
        ? 'text-emerald-800 dark:text-emerald-300'
        : 'text-sky-800 dark:text-sky-300';
}

function crewAccentClass(member: TreeCrewMember): string {
    if (member.employee_id === null) {
        return 'border-muted-foreground/25';
    }

    return member.is_deployed
        ? 'border-emerald-500/70 dark:border-emerald-400/70'
        : 'border-sky-500/70 dark:border-sky-400/70';
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
                'flex items-center gap-2 rounded-md border-l-2 py-0.5 pr-1 pl-1.5 text-xs',
                crewAccentClass(member),
                isMatch && 'bg-muted/40',
            )}
        >
            <span
                className={cn(
                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold',
                    isVacant
                        ? 'border border-dashed border-muted-foreground/30 text-muted-foreground/50'
                        : barAvatarClass(member),
                )}
            >
                {isVacant ? '—' : crewInitials(member.employee_name)}
            </span>
            <div className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block truncate',
                        crewNameClass(member),
                        isMatch && 'font-semibold',
                    )}
                >
                    {member.employee_name}
                </span>
                {!member.is_deployed && member.relieves_employee_name ? (
                    <span className="block truncate text-[10px] text-sky-700/80 dark:text-sky-300/80">
                        → {member.relieves_employee_name}
                    </span>
                ) : null}
            </div>
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
    const [rankOpen, setRankOpen] = useState(false);

    if (!rankMatchesSearch(rank, lowerSearch)) {
        return null;
    }

    const isExpanded = forceOpen || rankOpen;

    return (
        <Collapsible open={forceOpen || rankOpen} onOpenChange={setRankOpen}>
            <div
                className={cn(
                    'relative ml-3 border-l-2 pl-2',
                    isSelected ? 'border-primary' : 'border-border/50',
                )}
            >
                <div
                    className={cn(
                        'flex min-w-0 items-stretch rounded-md transition-colors',
                        isSelected &&
                            'bg-[color-mix(in_oklch,var(--primary)_10%,var(--background))]',
                        !isSelected && 'hover:bg-muted/30',
                    )}
                >
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex w-6 shrink-0 items-center justify-center text-muted-foreground/70 transition-colors hover:text-foreground focus-visible:outline-none"
                            aria-label={isExpanded ? `Collapse ${rank.rank_name}` : `Expand ${rank.rank_name}`}
                        >
                            <ChevronRight
                                className={cn(
                                    'h-3 w-3 transition-transform duration-200',
                                    isExpanded && 'rotate-90',
                                )}
                            />
                        </button>
                    </CollapsibleTrigger>
                    <button
                        type="button"
                        className="flex min-w-0 flex-1 items-center gap-2 py-1.5 pr-2 text-left focus-visible:outline-none"
                        onClick={() => onRowSelect(rowKey)}
                    >
                        <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded text-muted-foreground/80">
                            <Anchor className="h-3 w-3" />
                        </span>
                        <span
                            className={cn(
                                'truncate text-[11px] font-medium tracking-wide uppercase',
                                isSelected ? 'text-primary' : 'text-muted-foreground',
                            )}
                        >
                            {rank.rank_name}
                        </span>
                    </button>
                </div>

                <CollapsibleContent>
                    <div className="space-y-0.5 border-l border-border/30 py-1 pl-3 ml-2.5">
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
    const [open, setOpen] = useState(false);

    if (!vesselMatchesSearch(vessel, lowerSearch)) {
        return null;
    }

    const isExpanded = forceOpen || open;

    return (
        <Collapsible
            open={isExpanded}
            onOpenChange={setOpen}
            className="border-b border-border/40 last:border-b-0"
        >
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center gap-2.5 bg-muted/25 px-3 py-2.5 text-left transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                >
                    <ChevronRight
                        className={cn(
                            'h-4 w-4 shrink-0 text-foreground/70 transition-transform duration-200',
                            isExpanded && 'rotate-90',
                        )}
                    />
                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_oklch,var(--primary)_15%,var(--background))] text-primary">
                        <Ship className="h-4 w-4" />
                    </span>
                    <span className="min-w-0 flex-1 truncate text-sm font-bold tracking-tight text-foreground">
                        {vessel.vessel_name}
                    </span>
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-0.5 bg-background/50 py-1.5 pr-2">
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
                <p className="text-sm font-medium text-foreground/90">No planned vessels or ranks</p>
                <p className="max-w-[200px] text-xs leading-relaxed text-muted-foreground/70">
                    No planned assignments in this date range. Assign crew or sync from deployments.
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
