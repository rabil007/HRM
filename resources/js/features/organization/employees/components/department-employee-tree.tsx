import { Briefcase, ChevronRight, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import type { DepartmentTreeNode, DepartmentTreePositionNode } from '../types';

function findAncestorIds(
    nodes: DepartmentTreeNode[],
    targetId: number,
    ancestors: number[] = [],
): number[] | null {
    for (const node of nodes) {
        if (node.id === targetId) {
            return ancestors;
        }

        if (node.id !== null) {
            const found = findAncestorIds(node.children, targetId, [
                ...ancestors,
                node.id,
            ]);

            if (found !== null) {
                return found;
            }
        }
    }

    return null;
}

function findDepartmentIdForPosition(
    nodes: DepartmentTreeNode[],
    positionId: number,
): number | null {
    for (const node of nodes) {
        if (node.id !== null) {
            if (node.positions.some((position) => position.id === positionId)) {
                return node.id;
            }

            const found = findDepartmentIdForPosition(
                node.children,
                positionId,
            );

            if (found !== null) {
                return found;
            }
        }
    }

    return null;
}

function PositionTreeNodeRow({
    position,
    depth,
    selectedPositionId,
    onSelectPosition,
}: {
    position: DepartmentTreePositionNode;
    depth: number;
    selectedPositionId: number | null;
    onSelectPosition: (positionId: number) => void;
}) {
    const isSelected = selectedPositionId === position.id;

    return (
        <div
            style={{ paddingLeft: depth * 12 }}
            className="flex items-center gap-0.5"
        >
            <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center">
                <Briefcase className="h-3 w-3 text-muted-foreground" />
            </span>
            <button
                type="button"
                onClick={() => onSelectPosition(position.id)}
                className={cn(
                    'flex min-w-0 flex-1 items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors',
                    isSelected
                        ? 'bg-accent text-foreground dark:bg-white/10 dark:text-white'
                        : 'text-muted-foreground hover:bg-accent hover:text-foreground dark:text-zinc-300 dark:hover:bg-white/[0.06] dark:hover:text-white',
                )}
            >
                <span className="min-w-0 flex-1 truncate">{position.name}</span>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {position.count}
                </span>
            </button>
        </div>
    );
}

function DepartmentTreeNodeRow({
    node,
    depth,
    selectedDepartmentId,
    selectedPositionId,
    expandedIds,
    onToggleExpand,
    onSelectDepartment,
    onSelectPosition,
}: {
    node: DepartmentTreeNode;
    depth: number;
    selectedDepartmentId: number | null;
    selectedPositionId: number | null;
    expandedIds: Set<number>;
    onToggleExpand: (id: number, open: boolean) => void;
    onSelectDepartment: (id: number | null) => void;
    onSelectPosition: (positionId: number, departmentId: number) => void;
}) {
    const hasChildDepartments = node.children.length > 0;
    const hasPositions = node.positions.length > 0;
    const hasExpandableContent = hasChildDepartments || hasPositions;
    const isAllNode = node.id === null;
    const isSelected = isAllNode
        ? selectedDepartmentId === null && selectedPositionId === null
        : selectedDepartmentId === node.id && selectedPositionId === null;
    const isExpanded = node.id !== null && expandedIds.has(node.id);

    const rowButton = (
        <button
            type="button"
            onClick={() => onSelectDepartment(node.id)}
            className={cn(
                'flex min-w-0 flex-1 items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors',
                isSelected
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            )}
        >
            <span className="min-w-0 flex-1 truncate">{node.name}</span>
            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                {node.count}
            </span>
        </button>
    );

    if (!hasExpandableContent || node.id === null) {
        return (
            <div
                style={{ paddingLeft: depth * 12 }}
                className="flex items-center gap-0.5"
            >
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
            <div
                style={{ paddingLeft: depth * 12 }}
                className="flex items-center gap-0.5"
            >
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground dark:hover:bg-white/[0.06] dark:hover:text-zinc-200"
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
                        key={child.id}
                        node={child}
                        depth={depth + 1}
                        selectedDepartmentId={selectedDepartmentId}
                        selectedPositionId={selectedPositionId}
                        expandedIds={expandedIds}
                        onToggleExpand={onToggleExpand}
                        onSelectDepartment={onSelectDepartment}
                        onSelectPosition={onSelectPosition}
                    />
                ))}
                {node.positions.map((position) => (
                    <PositionTreeNodeRow
                        key={position.id}
                        position={position}
                        depth={depth + 1}
                        selectedPositionId={selectedPositionId}
                        onSelectPosition={(positionId) =>
                            onSelectPosition(positionId, node.id as number)
                        }
                    />
                ))}
            </CollapsibleContent>
        </Collapsible>
    );
}

export function DepartmentEmployeeTree({
    nodes,
    selectedDepartmentId,
    selectedPositionId,
    onSelectDepartment,
    onSelectPosition,
    className,
}: {
    nodes: DepartmentTreeNode[];
    selectedDepartmentId: number | null;
    selectedPositionId: number | null;
    onSelectDepartment: (id: number | null) => void;
    onSelectPosition: (positionId: number, departmentId: number) => void;
    className?: string;
}) {
    const departmentRoots = useMemo(
        () => nodes.filter((node) => node.id !== null),
        [nodes],
    );

    const allNode = useMemo(
        () => nodes.find((node) => node.id === null) ?? null,
        [nodes],
    );

    const initialExpandedIds = useMemo(() => {
        const expanded = new Set<number>();

        if (selectedDepartmentId !== null) {
            const ancestors =
                findAncestorIds(departmentRoots, selectedDepartmentId) ?? [];

            ancestors.forEach((id) => expanded.add(id));
            expanded.add(selectedDepartmentId);
        }

        if (selectedPositionId !== null) {
            const departmentId = findDepartmentIdForPosition(
                departmentRoots,
                selectedPositionId,
            );

            if (departmentId !== null) {
                const ancestors =
                    findAncestorIds(departmentRoots, departmentId) ?? [];

                ancestors.forEach((id) => expanded.add(id));
                expanded.add(departmentId);
            }
        }

        return expanded;
    }, [departmentRoots, selectedDepartmentId, selectedPositionId]);

    const [expandedIds, setExpandedIds] = useState<Set<number>>(
        () => initialExpandedIds,
    );

    useEffect(() => {
        setExpandedIds(initialExpandedIds);
    }, [initialExpandedIds]);

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
            <div className="mb-3 flex items-center gap-2 px-1 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                <Users className="h-3.5 w-3.5" />
                Department
            </div>

            <div className="space-y-0.5">
                {allNode ? (
                    <DepartmentTreeNodeRow
                        node={allNode}
                        depth={0}
                        selectedDepartmentId={selectedDepartmentId}
                        selectedPositionId={selectedPositionId}
                        expandedIds={expandedIds}
                        onToggleExpand={handleToggleExpand}
                        onSelectDepartment={onSelectDepartment}
                        onSelectPosition={onSelectPosition}
                    />
                ) : null}

                {departmentRoots.map((node) => (
                    <DepartmentTreeNodeRow
                        key={node.id}
                        node={node}
                        depth={0}
                        selectedDepartmentId={selectedDepartmentId}
                        selectedPositionId={selectedPositionId}
                        expandedIds={expandedIds}
                        onToggleExpand={handleToggleExpand}
                        onSelectDepartment={onSelectDepartment}
                        onSelectPosition={onSelectPosition}
                    />
                ))}
            </div>
        </div>
    );
}
