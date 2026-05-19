import { ChevronRight, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import type { DepartmentTreeNode } from '../types';

function findAncestorIds(
    nodes: DepartmentTreeNode[],
    targetId: number,
    ancestors: number[] = [],
): number[] | null {
    for (const node of nodes) {
        if (node.id === targetId) {
            return ancestors;
        }

        if (node.id !== null && node.children.length > 0) {
            const found = findAncestorIds(node.children, targetId, [...ancestors, node.id]);

            if (found !== null) {
                return found;
            }
        }
    }

    return null;
}

function DepartmentTreeNodeRow({
    node,
    depth,
    selectedId,
    expandedIds,
    onToggleExpand,
    onSelect,
}: {
    node: DepartmentTreeNode;
    depth: number;
    selectedId: number | null;
    expandedIds: Set<number>;
    onToggleExpand: (id: number, open: boolean) => void;
    onSelect: (id: number | null) => void;
}) {
    const hasChildren = node.children.length > 0;
    const isAllNode = node.id === null;
    const isSelected = isAllNode ? selectedId === null : selectedId === node.id;
    const isExpanded = node.id !== null && expandedIds.has(node.id);

    const rowButton = (
        <button
            type="button"
            onClick={() => onSelect(node.id)}
            className={cn(
                'flex min-w-0 flex-1 items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors',
                isSelected
                    ? 'bg-white/10 text-white'
                    : 'text-zinc-300 hover:bg-white/[0.06] hover:text-white',
            )}
        >
            <span className="min-w-0 flex-1 truncate">{node.name}</span>
            <span className="shrink-0 text-xs tabular-nums text-zinc-500">{node.count}</span>
        </button>
    );

    if (!hasChildren || node.id === null) {
        return (
            <div style={{ paddingLeft: depth * 12 }} className="flex items-center gap-0.5">
                <span className="inline-flex h-6 w-6 shrink-0" />
                {rowButton}
            </div>
        );
    }

    return (
        <Collapsible
            open={isExpanded}
            onOpenChange={(open) => onToggleExpand(node.id as number, open)}
        >
            <div style={{ paddingLeft: depth * 12 }} className="flex items-center gap-0.5">
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-zinc-500 hover:bg-white/[0.06] hover:text-zinc-200"
                        aria-label={isExpanded ? 'Collapse' : 'Expand'}
                    >
                        <ChevronRight
                            className={cn(
                                'h-3.5 w-3.5 transition-transform',
                                isExpanded && 'rotate-90',
                            )}
                        />
                    </button>
                </CollapsibleTrigger>
                {rowButton}
            </div>
            <CollapsibleContent>
                {node.children.map((child) => (
                    <DepartmentTreeNodeRow
                        key={child.id ?? `child-${child.name}`}
                        node={child}
                        depth={depth + 1}
                        selectedId={selectedId}
                        expandedIds={expandedIds}
                        onToggleExpand={onToggleExpand}
                        onSelect={onSelect}
                    />
                ))}
            </CollapsibleContent>
        </Collapsible>
    );
}

export function DepartmentEmployeeTree({
    nodes,
    selectedId,
    onSelect,
    className,
}: {
    nodes: DepartmentTreeNode[];
    selectedId: number | null;
    onSelect: (id: number | null) => void;
    className?: string;
}) {
    const departmentRoots = useMemo(
        () => nodes.filter((node) => node.id !== null),
        [nodes],
    );

    const allNode = useMemo(() => nodes.find((node) => node.id === null) ?? null, [nodes]);

    const initialExpandedIds = useMemo(() => {
        if (selectedId === null) {
            return new Set<number>();
        }

        const ancestors = findAncestorIds(departmentRoots, selectedId) ?? [];

        return new Set(ancestors);
    }, [departmentRoots, selectedId]);

    const [expandedIds, setExpandedIds] = useState<Set<number>>(() => initialExpandedIds);

    const handleToggleExpand = (id: number, open: boolean) => {
        setExpandedIds((current) => {
            const next = new Set(current);

            if (open) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });
    };

    return (
        <div className={cn('flex flex-col', className)}>
            <div className="mb-3 flex items-center gap-2 px-1 text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                <Users className="h-3.5 w-3.5" />
                Department
            </div>

            <div className="space-y-0.5">
                {allNode ? (
                    <DepartmentTreeNodeRow
                        node={allNode}
                        depth={0}
                        selectedId={selectedId}
                        expandedIds={expandedIds}
                        onToggleExpand={handleToggleExpand}
                        onSelect={onSelect}
                    />
                ) : null}

                {departmentRoots.map((node) => (
                    <DepartmentTreeNodeRow
                        key={node.id}
                        node={node}
                        depth={0}
                        selectedId={selectedId}
                        expandedIds={expandedIds}
                        onToggleExpand={handleToggleExpand}
                        onSelect={onSelect}
                    />
                ))}
            </div>
        </div>
    );
}
