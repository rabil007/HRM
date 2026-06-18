import { ChevronDown, ChevronRight, Ship } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import type { TreeRank, TreeVessel } from '../types';

function RankNode({
    rank,
    rowKey,
    isSelected,
    search,
    onRowSelect,
}: {
    rank: TreeRank;
    rowKey: string;
    isSelected: boolean;
    search: string;
    onRowSelect: (rowKey: string) => void;
}): ReactElement | null {
    const [rankOpen, setRankOpen] = useState(false);
    const lowerSearch = search.toLowerCase();

    const rankMatches =
        lowerSearch === '' ||
        rank.rank_name.toLowerCase().includes(lowerSearch) ||
        rank.crew.some((c) => c.employee_name.toLowerCase().includes(lowerSearch));

    if (!rankMatches) {
        return null;
    }

    return (
        <Collapsible open={rankOpen} onOpenChange={setRankOpen}>
            <div
                className={cn(
                    'flex items-center',
                    isSelected && 'bg-amber-50/50 dark:bg-amber-950/30',
                )}
            >
                <CollapsibleTrigger asChild>
                    <button className="flex items-center p-1 pl-7 text-muted-foreground hover:text-foreground">
                        {rankOpen ? (
                            <ChevronDown className="h-3 w-3" />
                        ) : (
                            <ChevronRight className="h-3 w-3" />
                        )}
                    </button>
                </CollapsibleTrigger>
                <button
                    className="flex flex-1 items-center gap-2 py-1.5 pr-3 text-left text-xs"
                    onClick={() => onRowSelect(rowKey)}
                >
                    <span className="truncate font-medium">{rank.rank_name}</span>
                    <span className="ml-auto shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                        {rank.crew.length}/{rank.required_count}
                    </span>
                </button>
            </div>
            <CollapsibleContent>
                <div className="pb-1 pl-10">
                    {rank.crew.length === 0 ? (
                        <p className="py-1 text-xs text-muted-foreground/60">No crew planned</p>
                    ) : (
                        rank.crew.map((member, idx) => (
                            <div
                                key={member.employee_id != null ? `emp-${member.employee_id}` : `vacant-${idx}`}
                                className={cn(
                                    'py-0.5 text-xs text-foreground/80',
                                    lowerSearch !== '' &&
                                        member.employee_name.toLowerCase().includes(lowerSearch) &&
                                        'font-semibold text-foreground',
                                )}
                            >
                                <span className="truncate">{member.employee_name}</span>
                            </div>
                        ))
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function VesselNode({
    vessel,
    search,
    selectedRowKey,
    onRowSelect,
}: {
    vessel: TreeVessel;
    search: string;
    selectedRowKey: string | null;
    onRowSelect: (rowKey: string) => void;
}): ReactElement {
    const [open, setOpen] = useState(true);
    const lowerSearch = search.toLowerCase();

    const hasMatch =
        lowerSearch === '' ||
        vessel.vessel_name.toLowerCase().includes(lowerSearch) ||
        vessel.ranks.some(
            (r) =>
                r.rank_name.toLowerCase().includes(lowerSearch) ||
                r.crew.some((c) => c.employee_name.toLowerCase().includes(lowerSearch)),
        );

    if (!hasMatch) {
        return <></>;
    }

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger asChild>
                <button className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold hover:bg-muted/50">
                    {open ? (
                        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                    <Ship className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span className="truncate">{vessel.vessel_name}</span>
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
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
                        />
                    );
                })}
            </CollapsibleContent>
        </Collapsible>
    );
}

export function VesselRankTree({ tree, search, selectedRowKey, onRowSelect }: {
    tree: TreeVessel[];
    search: string;
    selectedRowKey: string | null;
    onRowSelect: (rowKey: string) => void;
}): ReactElement {
    if (tree.length === 0) {
        return (
            <div className="flex h-full items-center justify-center p-4 text-center text-xs text-muted-foreground">
                No vessels with manning configured.
            </div>
        );
    }

    return (
        <div className="flex flex-col overflow-y-auto">
            {tree.map((vessel) => (
                <VesselNode
                    key={vessel.vessel_id}
                    vessel={vessel}
                    search={search}
                    selectedRowKey={selectedRowKey}
                    onRowSelect={onRowSelect}
                />
            ))}
        </div>
    );
}
