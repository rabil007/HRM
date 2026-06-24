import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Network, Briefcase, FoldVertical, UnfoldVertical } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type TreeDepartment = {
    id: number;
    parent_id: number | null;
    branch_id: number | null;
    name: string;
    users_count: number;
};

export type TreePosition = {
    id: number;
    department_id: number | null;
    title: string;
    grade: string | null;
    status: string;
    users_count: number;
};

// We define a generic TreeNode that handles Department or Position
export type OrgTreeNode = {
    id: string; // dept-2, pos-3
    type: 'parent_department' | 'child_department' | 'position';
    name: string;
    subtitle?: string;
    users_count?: number;
    status?: string;
    originalId: number;
    children: OrgTreeNode[];
};

export function buildOrgTree(departments: TreeDepartment[], positions: TreePosition[]): OrgTreeNode[] {
    const roots: OrgTreeNode[] = [];
    const nodeMap = new Map<string, OrgTreeNode>();

    // 1. Initialize Department nodes
    departments.forEach((dept) => {
        const id = `dept-${dept.id}`;
        const type = dept.parent_id === null ? 'parent_department' : 'child_department';
        const node: OrgTreeNode = {
            id,
            type,
            name: dept.name,
            users_count: dept.users_count,
            originalId: dept.id,
            children: [],
        };
        nodeMap.set(id, node);
    });

    // 2. Initialize Position nodes
    positions.forEach((pos) => {
        const id = `pos-${pos.id}`;
        const node: OrgTreeNode = {
            id,
            type: 'position',
            name: pos.title,
            subtitle: pos.grade ? `Grade: ${pos.grade}` : undefined,
            status: pos.status,
            users_count: pos.users_count,
            originalId: pos.id,
            children: [],
        };
        nodeMap.set(id, node);
    });

    // 3. Link Departments to Parent Departments
    departments.forEach((dept) => {
        const node = nodeMap.get(`dept-${dept.id}`);
        if (!node) return;

        if (dept.parent_id !== null) {
            const parent = nodeMap.get(`dept-${dept.parent_id}`);
            if (parent) {
                parent.children.push(node);
            } else {
                roots.push(node); // Fallback
            }
        } else {
            roots.push(node); // No parent
        }
    });

    // 4. Link Positions to Departments
    positions.forEach((pos) => {
        const node = nodeMap.get(`pos-${pos.id}`);
        if (!node) return;

        if (pos.department_id !== null) {
            const parent = nodeMap.get(`dept-${pos.department_id}`);
            if (parent) {
                parent.children.push(node);
            } else {
                roots.push(node);
            }
        } else {
            roots.push(node);
        }
    });

    return roots;
}

const HEADER_COLORS = {
    parent_department: 'bg-indigo-500/90 text-white',
    child_department: 'bg-emerald-500/90 text-white',
    position: 'bg-orange-500/90 text-white',
};

function OrgNodeCard({ 
    node, 
    expandCounter, 
    collapseCounter 
}: { 
    node: OrgTreeNode; 
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
    const colorClass = HEADER_COLORS[node.type];

    const handleClick = () => {
        if (node.type === 'parent_department' || node.type === 'child_department') {
            router.visit(`/organization/departments/${node.originalId}`);
        } else if (node.type === 'position') {
            router.visit(`/organization/positions/${node.originalId}`);
        }
    };

    return (
        <li className="org-tree-node">
            <div className="flex flex-col items-center">
                <div 
                    className="w-56 bg-card/95 border border-border/60 shadow-md hover:shadow-lg transition-all duration-200 text-left overflow-hidden rounded-xl cursor-pointer"
                    onClick={handleClick}
                >
                    <div className={cn("h-8 flex items-center justify-center px-2 gap-2", colorClass)}>
                        {(node.type === 'parent_department' || node.type === 'child_department') && <Network className="w-3.5 h-3.5" />}
                        {node.type === 'position' && <Briefcase className="w-3.5 h-3.5" />}
                        <span className="text-xs font-semibold tracking-wider truncate">
                            {node.name}
                        </span>
                    </div>

                    <div className="flex flex-col justify-between p-4 min-h-[70px]">
                        {node.subtitle && (
                            <span className="text-xs font-medium text-muted-foreground mb-1">
                                {node.subtitle}
                            </span>
                        )}
                        {node.users_count !== undefined && (
                            <span className="text-xs font-semibold text-emerald-500 dark:text-emerald-400 mt-1">
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

            {hasChildren && isUnfolded && (
                <ul>
                    {node.children.map((child) => (
                        <OrgNodeCard 
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

export function PositionTreeView({
    departments,
    positions,
}: {
    departments: TreeDepartment[];
    positions: TreePosition[];
}) {
    const tree = buildOrgTree(departments, positions);
    const [expandCounter, setExpandCounter] = useState(0);
    const [collapseCounter, setCollapseCounter] = useState(0);

    return (
        <div className="glass-card rounded-xl border border-border/50 bg-background/50 backdrop-blur-sm p-8 overflow-x-auto">
            <div className="flex flex-wrap items-center justify-between gap-4 mb-8">
                {/* Legend */}
                <div className="flex flex-wrap items-center gap-6 text-sm font-medium">
                    <div className="flex items-center gap-2">
                        <div className="w-3 h-3 rounded-full bg-indigo-500/90" />
                        <span className="text-muted-foreground">Parent Department</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="w-3 h-3 rounded-full bg-emerald-500/90" />
                        <span className="text-muted-foreground">Sub-department</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="w-3 h-3 rounded-full bg-orange-500/90" />
                        <span className="text-muted-foreground">Position</span>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2">
                    <Button 
                        variant="outline" 
                        size="sm" 
                        onClick={() => setCollapseCounter(c => c + 1)}
                        className="h-8 gap-1"
                    >
                        <FoldVertical className="w-3.5 h-3.5" />
                        <span>Fold All</span>
                    </Button>
                    <Button 
                        variant="outline" 
                        size="sm" 
                        onClick={() => setExpandCounter(c => c + 1)}
                        className="h-8 gap-1"
                    >
                        <UnfoldVertical className="w-3.5 h-3.5" />
                        <span>Unfold All</span>
                    </Button>
                </div>
            </div>

            {tree.length > 0 ? (
                <div className="org-tree min-w-max pb-8">
                    <ul>
                        {tree.map((node) => (
                            <OrgNodeCard 
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
                    No data found to display in tree view.
                </div>
            )}
        </div>
    );
}
