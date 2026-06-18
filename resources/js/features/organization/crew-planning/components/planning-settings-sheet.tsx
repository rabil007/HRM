import { useForm } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { updateSettings as updatePlanningSettings } from '@/actions/App/Http/Controllers/Organization/CrewPlanningController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import {
    applyDepartmentToggle,
    flattenDepartmentTreeIds,
    getDepartmentCheckState,
} from '../lib/department-tree';
import type { PlanningDepartmentNode, PlanningSettings } from '../types';

type FormData = {
    pool_department_ids: number[];
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    departmentTree: PlanningDepartmentNode[];
    settings: PlanningSettings;
};

function DepartmentTreeNodeRow({
    node,
    depth,
    selectedIds,
    onToggle,
}: {
    node: PlanningDepartmentNode;
    depth: number;
    selectedIds: Set<number>;
    onToggle: (node: PlanningDepartmentNode, checked: boolean) => void;
}): ReactElement {
    const [open, setOpen] = useState(depth === 0);
    const checkState = getDepartmentCheckState(node, selectedIds);
    const hasChildren = node.children.length > 0;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div
                className={cn(
                    'flex items-center gap-2 rounded-xl border border-border/60 px-3 py-2.5 hover:bg-muted/40',
                    depth > 0 && 'mt-2',
                )}
                style={{ marginLeft: depth * 16 }}
            >
                {hasChildren ? (
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                            aria-label={`Toggle ${node.name}`}
                        >
                            <ChevronRight
                                className={cn(
                                    'h-3.5 w-3.5 transition-transform',
                                    open && 'rotate-90',
                                )}
                            />
                        </button>
                    </CollapsibleTrigger>
                ) : (
                    <span className="inline-flex h-5 w-5 shrink-0" />
                )}

                <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                    <Checkbox
                        checked={
                            checkState === 'indeterminate' ? 'indeterminate' : checkState === 'checked'
                        }
                        onCheckedChange={(value) => onToggle(node, value === true)}
                    />
                    <span className="truncate text-sm font-medium">{node.name}</span>
                </label>
            </div>

            {hasChildren ? (
                <CollapsibleContent>
                    {node.children.map((child) => (
                        <DepartmentTreeNodeRow
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            selectedIds={selectedIds}
                            onToggle={onToggle}
                        />
                    ))}
                </CollapsibleContent>
            ) : null}
        </Collapsible>
    );
}

export function PlanningSettingsSheet({
    open,
    onOpenChange,
    departmentTree,
    settings,
}: Props): ReactElement {
    const form = useForm<FormData>({
        pool_department_ids: settings.pool_department_ids,
    });

    useEffect(() => {
        if (open) {
            form.setData('pool_department_ids', settings.pool_department_ids);
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, settings.pool_department_ids]);

    const selectedSet = new Set(form.data.pool_department_ids);
    const allDepartmentIds = flattenDepartmentTreeIds(departmentTree);

    const toggleDepartment = (node: PlanningDepartmentNode, checked: boolean): void => {
        form.setData(
            'pool_department_ids',
            applyDepartmentToggle(form.data.pool_department_ids, node, checked),
        );
    };

    const handleSubmit = (): void => {
        form.put(updatePlanningSettings.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const allSelected =
        allDepartmentIds.length > 0 && allDepartmentIds.every((id) => selectedSet.has(id));
    const noneSelected = form.data.pool_department_ids.length === 0;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="flex w-full flex-col rounded-none p-0 sm:max-w-md">
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Planning settings
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Choose which departments appear in the Available crew pool and assignment
                        picker. Selecting a parent includes all child departments. Leave all
                        unchecked to include every active employee.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-4 overflow-y-auto p-8">
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Available crew departments
                        </p>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-xs"
                                disabled={allDepartmentIds.length === 0}
                                onClick={() =>
                                    form.setData('pool_department_ids', allDepartmentIds)
                                }
                            >
                                Select all
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-xs"
                                onClick={() => form.setData('pool_department_ids', [])}
                            >
                                Clear
                            </Button>
                        </div>
                    </div>

                    {noneSelected ? (
                        <p className="rounded-xl border border-dashed px-3 py-2 text-xs text-muted-foreground">
                            No departments selected — all active employees are shown in Available
                            crew.
                        </p>
                    ) : null}

                    {allSelected && allDepartmentIds.length > 0 ? (
                        <p className="rounded-xl border border-dashed px-3 py-2 text-xs text-muted-foreground">
                            All departments selected — same as showing every active employee.
                        </p>
                    ) : null}

                    {departmentTree.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No active departments found for this company.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {departmentTree.map((node) => (
                                <DepartmentTreeNodeRow
                                    key={node.id}
                                    node={node}
                                    depth={0}
                                    selectedIds={selectedSet}
                                    onToggle={toggleDepartment}
                                />
                            ))}
                        </div>
                    )}

                    {form.errors.pool_department_ids ? (
                        <p className="text-xs font-medium text-destructive">
                            {form.errors.pool_department_ids}
                        </p>
                    ) : null}
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="h-11 flex-1 rounded-xl font-semibold"
                        disabled={form.processing}
                        onClick={handleSubmit}
                    >
                        {form.processing ? 'Saving…' : 'Save settings'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
