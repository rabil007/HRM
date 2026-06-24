import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Department } from '../types';
import { router } from '@inertiajs/react';
import { Users } from 'lucide-react';

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

function OrgCard({
    node,
}: {
    node: DepartmentTreeNode;
}) {
    const [isUnfolded, setIsUnfolded] = useState(true);
    const hasChildren = node.children.length > 0;

    return (
        <li className="org-tree-node">
            {/* The Card */}
            <div className="flex flex-col items-center">
                <div 
                    className="w-64 glass-card rounded-md shadow-sm border border-border/60 overflow-hidden text-left bg-card hover:border-primary/50 transition-colors cursor-pointer"
                    onClick={() => router.visit(`/organization/departments/${node.id}`)}
                >
                    {/* Top colored bar based on some logic or just a nice gradient/color */}
                    <div className="h-8 bg-primary/20 border-b border-border/40 flex items-center justify-center">
                        <span className="text-xs font-semibold uppercase tracking-wider text-primary truncate px-2">
                            {node.name}
                        </span>
                    </div>

                    <div className="p-3">
                        <div className="flex items-center gap-2 mb-2">
                            <span className="text-xs text-muted-foreground font-medium">
                                {node.users_count} Employee{node.users_count !== 1 ? 's' : ''}
                            </span>
                        </div>
                        {node.manager && (
                            <div className="flex items-center gap-2 mt-2 pt-2 border-t border-border/40">
                                <div className="w-5 h-5 rounded-full bg-accent flex items-center justify-center shrink-0">
                                    <span className="text-[9px] font-bold text-accent-foreground">
                                        {node.manager.name.charAt(0)}
                                    </span>
                                </div>
                                <span className="text-[11px] font-medium truncate">{node.manager.name}</span>
                            </div>
                        )}
                    </div>

                    {hasChildren && (
                        <div 
                            className="w-full bg-muted/30 border-t border-border/40 py-1.5 flex items-center justify-center hover:bg-muted/50 transition-colors cursor-pointer"
                            onClick={(e) => {
                                e.stopPropagation();
                                setIsUnfolded(!isUnfolded);
                            }}
                        >
                            <span className="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider flex items-center justify-between w-full px-4">
                                <span>{isUnfolded ? 'Fold' : 'Unfold'}</span>
                                <span className="flex items-center gap-1"><Users className="w-3 h-3"/> {node.children.length}</span>
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
        <div className="glass-card rounded-xl border border-border/50 bg-background/50 backdrop-blur-sm p-6 overflow-x-auto">
            {tree.length > 0 ? (
                <div className="org-tree min-w-max">
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
