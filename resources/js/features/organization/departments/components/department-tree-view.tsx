import { router } from '@inertiajs/react';
import { Network, FoldVertical, UnfoldVertical } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// We define a Tree Node type which extends the flat department
export type DepartmentTreeNode = {
    id: number;
    parent_id: number | null;
    name: string;
    code: string | null;
    status: string;
    manager: { id: number; name: string } | null;
    branch: { id: number; name: string } | null;
    positions_count: number;
    users_count: number;
    children: DepartmentTreeNode[];
};

// Helper function to build the tree
export function buildTree(departments: any[]): DepartmentTreeNode[] {
    const map = new Map<number, DepartmentTreeNode>();
    const roots: DepartmentTreeNode[] = [];

    // Initialize all nodes
    departments.forEach((dept) => {
        map.set(dept.id, { ...dept, children: [] });
    });

    // Build the tree
    departments.forEach((dept) => {
        const node = map.get(dept.id);

        if (node) {
            if (dept.parent_id === null) {
                roots.push(node);
            } else {
                const parent = map.get(dept.parent_id);

                if (parent) {
                    parent.children.push(node);
                } else {
                    // Parent not found, treat as root to avoid orphan loss
                    roots.push(node);
                }
            }
        }
    });

    return roots;
}

const HEADER_COLORS = {
    parent_department: 'bg-indigo-500/90 text-white',
    child_department: 'bg-emerald-500/90 text-white',
};

function OrgCard({
    node,
    expandCounter,
    collapseCounter,
}: {
    node: DepartmentTreeNode;
    expandCounter: number;
    collapseCounter: number;
}) {
    const [isUnfolded, setIsUnfolded] = useState(true);
    const [lastExpand, setLastExpand] = useState(expandCounter);
    const [lastCollapse, setLastCollapse] = useState(collapseCounter);

    if (expandCounter !== lastExpand) {
        setIsUnfolded(true);
        setLastExpand(expandCounter);
    }

    if (collapseCounter !== lastCollapse) {
        setIsUnfolded(false);
        setLastCollapse(collapseCounter);
    }

    const hasChildren = node.children.length > 0;

    const nodeType =
        node.parent_id === null ? 'parent_department' : 'child_department';
    const colorClass = HEADER_COLORS[nodeType];

    return (
        <li className="org-tree-node">
            {/* The Card */}
            <div className="flex flex-col items-center">
                <div
                    className="w-56 cursor-pointer overflow-hidden rounded-xl border border-border/60 bg-card/95 text-left shadow-md transition-all duration-200 hover:shadow-lg"
                    onClick={() =>
                        router.visit(`/organization/departments/${node.id}`)
                    }
                >
                    {/* Top colored bar with name */}
                    <div
                        className={cn(
                            'flex h-8 items-center justify-center gap-2 px-2',
                            colorClass,
                        )}
                    >
                        <Network className="h-3.5 w-3.5" />
                        <span className="truncate text-xs font-semibold tracking-wider">
                            {node.name}
                        </span>
                    </div>

                    <div className="flex min-h-[70px] flex-col justify-between p-4">
                        {node.parent_id === null && node.manager ? (
                            <div className="flex flex-col gap-2">
                                <div className="flex items-center gap-2">
                                    <Avatar className="h-6 w-6">
                                        <AvatarFallback className="bg-primary/10 text-[10px] font-bold text-primary">
                                            {node.manager.name.charAt(0)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="truncate text-xs font-semibold text-foreground">
                                        {node.manager.name}
                                    </span>
                                </div>
                                <span className="text-xs font-semibold text-emerald-500 dark:text-emerald-400">
                                    {node.users_count} Employee
                                    {node.users_count !== 1 ? 's' : ''}
                                </span>
                            </div>
                        ) : (
                            <span className="text-xs font-semibold text-emerald-500 dark:text-emerald-400">
                                {node.users_count} Employee
                                {node.users_count !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>

                    {hasChildren && (
                        <div
                            className="flex w-full cursor-pointer items-center justify-center border-t border-border/40 bg-muted/50 py-2 transition-colors hover:bg-muted/80"
                            onClick={(e) => {
                                e.stopPropagation();
                                setIsUnfolded(!isUnfolded);
                            }}
                        >
                            <span className="flex w-full items-center justify-between px-4 text-[11px] font-semibold text-muted-foreground">
                                <span>{isUnfolded ? 'Fold' : 'Unfold'}</span>
                                <span className="flex items-center gap-1.5">
                                    {node.children.length}
                                    <Network className="h-3.5 w-3.5" />
                                </span>
                            </span>
                        </div>
                    )}
                </div>
            </div>

            {/* Children container */}
            {hasChildren && isUnfolded && (
                <ul>
                    {node.children.map((child) => (
                        <OrgCard
                            key={child.id}
                            node={child}
                            expandCounter={expandCounter}
                            collapseCounter={collapseCounter}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

export function DepartmentTreeView({ departments }: { departments: any[] }) {
    const tree = buildTree(departments);
    const [expandCounter, setExpandCounter] = useState(0);
    const [collapseCounter, setCollapseCounter] = useState(0);

    return (
        <div className="overflow-x-auto rounded-xl border glass-card border-border/50 bg-background/50 p-8 backdrop-blur-sm">
            <div className="mb-8 flex flex-wrap items-center justify-between gap-4">
                {/* Legend */}
                <div className="flex flex-wrap items-center gap-6 text-sm font-medium">
                    <div className="flex items-center gap-2">
                        <div className="h-3 w-3 rounded-full bg-indigo-500/90" />
                        <span className="text-muted-foreground">
                            Parent Department
                        </span>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="h-3 w-3 rounded-full bg-emerald-500/90" />
                        <span className="text-muted-foreground">
                            Sub-department
                        </span>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setCollapseCounter((c) => c + 1)}
                        className="h-8 gap-1"
                    >
                        <FoldVertical className="h-3.5 w-3.5" />
                        <span>Fold All</span>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setExpandCounter((c) => c + 1)}
                        className="h-8 gap-1"
                    >
                        <UnfoldVertical className="h-3.5 w-3.5" />
                        <span>Unfold All</span>
                    </Button>
                </div>
            </div>

            {tree.length > 0 ? (
                <div className="org-tree min-w-max pb-8">
                    <ul>
                        {tree.map((node) => (
                            <OrgCard
                                key={node.id}
                                node={node}
                                expandCounter={expandCounter}
                                collapseCounter={collapseCounter}
                            />
                        ))}
                    </ul>
                </div>
            ) : (
                <div className="py-12 text-center text-sm text-muted-foreground">
                    No departments found to display in tree view.
                </div>
            )}
        </div>
    );
}
