import { Head, useForm } from '@inertiajs/react';
import { ChevronRight, Settings2, Sliders, CheckCircle2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { update as updateSettings } from '@/actions/App/Http/Controllers/Organization/CrewOperationsSettingsController';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import {
    applyDepartmentToggle,
    flattenDepartmentTreeIds,
    getDepartmentCheckState,
} from '@/features/organization/crew-planning/lib/department-tree';
import type { PlanningDepartmentNode, PlanningSettings } from '@/features/organization/crew-planning/types';

type FormData = {
    pool_department_ids: number[];
};

type Props = {
    department_tree: PlanningDepartmentNode[];
    crew_settings: PlanningSettings;
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
                    'flex items-center gap-2 rounded-xl border border-border/40 px-3 py-2.5 hover:bg-muted/40 transition-colors',
                    depth > 0 && 'mt-2',
                )}
                style={{ marginLeft: depth * 16 }}
            >
                {hasChildren ? (
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted transition-colors"
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

                <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3 select-none">
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

export default function CrewOperationsSettings({
    department_tree,
    crew_settings,
}: Props): ReactElement {
    const form = useForm<FormData>({
        pool_department_ids: crew_settings.pool_department_ids,
    });

    useEffect(() => {
        form.setData('pool_department_ids', crew_settings.pool_department_ids);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [crew_settings.pool_department_ids]);

    const selectedSet = new Set(form.data.pool_department_ids);
    const allDepartmentIds = flattenDepartmentTreeIds(department_tree);

    const toggleDepartment = (node: PlanningDepartmentNode, checked: boolean): void => {
        form.setData(
            'pool_department_ids',
            applyDepartmentToggle(form.data.pool_department_ids, node, checked),
        );
    };

    const handleSubmit = (e: React.FormEvent): void => {
        e.preventDefault();
        form.put(updateSettings.url(), {
            preserveScroll: true,
        });
    };

    const allSelected =
        allDepartmentIds.length > 0 && allDepartmentIds.every((id) => selectedSet.has(id));
    const noneSelected = form.data.pool_department_ids.length === 0;

    return (
        <Main>
            <Head title="Crew Operations Settings" />

            {/* Page Header */}
            <div className="mb-8 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <Settings2 className="h-4 w-4 text-primary shrink-0 animate-pulse" />
                    <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                        Crew Operations
                    </span>
                </div>
                <h1 className="text-4xl font-extrabold tracking-tight bg-gradient-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                    Settings
                </h1>
                <p className="text-sm text-muted-foreground/80 font-medium max-w-2xl">
                    Centralized hub to manage configuration and preferences for all crew operation modules (Planning, Deployments, Manning).
                </p>
            </div>

            <form onSubmit={handleSubmit} className="max-w-3xl space-y-6">
                {/* Planning Module Settings Card */}
                <Card className="border-border bg-card/60 backdrop-blur-md dark:border-white/5 dark:bg-white/[0.02] overflow-hidden shadow-sm">
                    <CardHeader className="border-b border-border/50 dark:border-white/5 bg-muted/20 dark:bg-white/[0.01] p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-2 bg-primary/10 rounded-xl text-primary">
                                <Sliders className="h-5 w-5" />
                            </div>
                            <div>
                                <CardTitle className="text-lg font-bold">Crew Planning Configuration</CardTitle>
                                <CardDescription className="text-sm text-muted-foreground mt-0.5">
                                    Configure default filtering behavior and employee availability rules for the Planning module.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-6 space-y-6">
                        <div className="space-y-2">
                            <h3 className="text-sm font-semibold text-foreground">Crew Departments Pool</h3>
                            <p className="text-xs text-muted-foreground/95 leading-relaxed">
                                Choose which departments appear in the Crew planning sidebar and assignment picker. Selecting a parent department automatically includes all its children. Leave all unchecked to show all active employees across the entire organization.
                            </p>
                        </div>

                        <div className="flex flex-col gap-4">
                            <div className="flex items-center justify-between gap-2 border-b border-border/40 pb-3">
                                <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Available Departments
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 px-2.5 text-xs rounded-lg hover:bg-muted/60"
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
                                        className="h-7 px-2.5 text-xs rounded-lg hover:bg-muted/60"
                                        onClick={() => form.setData('pool_department_ids', [])}
                                    >
                                        Clear
                                    </Button>
                                </div>
                            </div>

                            {noneSelected ? (
                                <div className="flex items-center gap-2 rounded-xl border border-dashed border-border/80 px-4 py-3 text-xs text-muted-foreground bg-muted/10">
                                    <CheckCircle2 className="h-4 w-4 text-primary shrink-0" />
                                    <span>No departments selected — showing every active employee in Crew Planning.</span>
                                </div>
                            ) : null}

                            {allSelected && allDepartmentIds.length > 0 ? (
                                <div className="flex items-center gap-2 rounded-xl border border-dashed border-border/80 px-4 py-3 text-xs text-muted-foreground bg-muted/10">
                                    <CheckCircle2 className="h-4 w-4 text-primary shrink-0" />
                                    <span>All departments selected — same as showing every active employee.</span>
                                </div>
                            ) : null}

                            {department_tree.length === 0 ? (
                                <p className="text-sm text-muted-foreground/80 py-4 text-center">
                                    No active departments found for this company.
                                </p>
                            ) : (
                                <div className="space-y-2 max-h-[350px] overflow-y-auto pr-1">
                                    {department_tree.map((node) => (
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
                    </CardContent>
                </Card>

                {/* Form Actions */}
                <div className="flex items-center justify-end gap-3 pt-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="h-10 px-5 rounded-xl text-muted-foreground"
                        disabled={form.processing}
                        onClick={() => form.reset()}
                    >
                        Reset
                    </Button>
                    <Button
                        type="submit"
                        className="h-10 px-6 rounded-xl font-semibold bg-primary hover:bg-primary/95 transition-all shadow-md shadow-primary/10"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing ? 'Saving changes…' : 'Save settings'}
                    </Button>
                </div>
            </form>
        </Main>
    );
}
