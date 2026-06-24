import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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

const HEADER_COLORS = [
    'bg-blue-500/80 text-white',
    'bg-orange-400/80 text-white',
    'bg-indigo-500/80 text-white',
    'bg-emerald-500/80 text-white',
    'bg-rose-500/80 text-white',
    'bg-cyan-400/80 text-white',
    'bg-violet-500/80 text-white',
    'bg-amber-500/80 text-white',
];

function OrgCard({
    node,
}: {
    node: DepartmentTreeNode;
}) {
    const [isUnfolded, setIsUnfolded] = useState(true);
    const hasChildren = node.children.length > 0;
    
    // Pick a deterministic color based on ID
    const colorClass = HEADER_COLORS[node.id % HEADER_COLORS.length];

    return (
        <li className="org-tree-node">
            {/* The Card */}
            <div className="flex flex-col items-center">
                <div 
                    className="w-56 bg-card/95 border border-border/60 shadow-md hover:shadow-lg transition-all duration-200 text-left overflow-hidden rounded-xl cursor-pointer"
                    onClick={() => router.visit(`/organization/departments/${node.id}`)}
                >
                    {/* Top colored bar with name */}
                    <div className={cn("h-8 flex items-center justify-center px-2 gap-2", colorClass)}>
                        <Network className="w-3.5 h-3.5" />
                        <span className="text-xs font-semibold tracking-wider truncate">
                            {node.name}
                        </span>
                    </div>

                    <div className="flex flex-col justify-between p-4 min-h-[70px]">
                        {node.manager ? (
                            <div className="flex flex-col gap-2">
                                <div className="flex items-center gap-2">
                                    <Avatar className="h-6 w-6">
                                        <AvatarFallback className="text-[10px] bg-primary/10 text-primary font-bold">
                                            {node.manager.name.charAt(0)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="text-xs font-semibold text-foreground truncate">
                                        {node.manager.name}
                                    </span>
                                </div>
                                <span className="text-xs font-semibold text-emerald-500 dark:text-emerald-400">
                                    {node.users_count} Employee{node.users_count !== 1 ? 's' : ''}
                                </span>
                            </div>
                        ) : (
                            <span className="text-xs font-semibold text-emerald-500 dark:text-emerald-400">
                                {node.users_count} Employee{node.users_count !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>

                    {hasChildren && (
                        <div 
                            className="w-full bg-muted/50 border-t border-border/40 py-2 flex items-center justify-center hover:bg-muted/80 transition-colors cursor-pointer"
                            onClick={(e) => {
                                e.stopPropagation();
                                setIsUnfolded(!isUnfolded);
                            }}
                        >
                            <span className="text-[11px] font-semibold text-muted-foreground flex items-center justify-between w-full px-4">
                                <span>{isUnfolded ? 'Fold' : 'Unfold'}</span>
                                <span className="flex items-center gap-1.5">
                                    {node.children.length}
                                    <Network className="w-3.5 h-3.5"/> 
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
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

export function DepartmentTreeView({
    departments,
}: {
    departments: any[];
}) {
    const tree = buildTree(departments);

    return (
        <div className="glass-card rounded-xl border border-border/50 bg-background/50 backdrop-blur-sm p-8 overflow-x-auto">
            {tree.length > 0 ? (
                <div className="org-tree min-w-max pb-8">
                    <ul>
                        {tree.map((node) => (
                            <OrgCard
                                key={node.id}
                                node={node}
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
