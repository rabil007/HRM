import { Head, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    CheckCircle2,
    Clock3,
    Home,
    RotateCcw,
    Save,
    Sliders,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { update as updateSettings } from '@/actions/App/Http/Controllers/Organization/CrewOperationsSettingsController';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    applyDepartmentToggle,
    flattenDepartmentTreeIds,
    getDepartmentCheckState,
} from '@/features/organization/crew-planning/lib/department-tree';
import type {
    PlanningDepartmentNode,
    PlanningSettings,
} from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

type FormData = {
    pool_department_ids: number[];
    max_home_days: number;
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
    const [open, setOpen] = useState(false);
    const checkState = getDepartmentCheckState(node, selectedIds);
    const hasChildren = node.children.length > 0;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div
                className={cn(
                    'group flex items-center gap-2 rounded-xl border px-3 py-2.5 transition-all',
                    depth === 0
                        ? 'border-border/70 bg-card/80 shadow-xs hover:border-primary/25'
                        : 'mt-1.5 border-transparent bg-muted/20 hover:border-border/60 hover:bg-muted/40',
                )}
                style={{ marginLeft: depth * 12 }}
            >
                {hasChildren ? (
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted"
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
                    <span className="inline-flex h-6 w-6 shrink-0" />
                )}

                <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3 select-none">
                    <Checkbox
                        checked={
                            checkState === 'indeterminate'
                                ? 'indeterminate'
                                : checkState === 'checked'
                        }
                        onCheckedChange={(value) =>
                            onToggle(node, value === true)
                        }
                    />
                    <span
                        className={cn(
                            'truncate text-sm',
                            depth === 0 ? 'font-semibold' : 'font-medium',
                        )}
                    >
                        {node.name}
                    </span>
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
        max_home_days: crew_settings.max_home_days,
    });

    useEffect(() => {
        form.setData({
            pool_department_ids: crew_settings.pool_department_ids,
            max_home_days: crew_settings.max_home_days,
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [crew_settings.pool_department_ids, crew_settings.max_home_days]);

    const selectedSet = new Set(form.data.pool_department_ids);
    const allDepartmentIds = flattenDepartmentTreeIds(department_tree);

    const toggleDepartment = (
        node: PlanningDepartmentNode,
        checked: boolean,
    ): void => {
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
        allDepartmentIds.length > 0 &&
        allDepartmentIds.every((id) => selectedSet.has(id));
    const noneSelected = form.data.pool_department_ids.length === 0;
    const selectionLabel =
        noneSelected || allSelected
            ? 'All active employees'
            : 'Custom department pool';

    return (
        <Main>
            <Head title="Crew Operations Settings" />

            <PageHeader
                kicker="Crew Operations"
                title="Settings"
                description="Set the company-wide defaults used by Crew Planning, assignments, and vessel manning."
            />

            <form
                onSubmit={handleSubmit}
                className="grid max-w-6xl items-start gap-6 lg:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.75fr)]"
            >
                <Card className="overflow-hidden border-border/80 bg-card/70 shadow-sm backdrop-blur-md dark:border-white/8 dark:bg-white/[0.03]">
                    <CardHeader className="border-b border-border/60 bg-linear-to-r from-primary/[0.07] via-muted/20 to-transparent p-5 sm:p-6 dark:border-white/6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
                                    <Sliders className="h-5 w-5" />
                                </div>
                                <div className="space-y-1">
                                    <CardTitle className="text-lg font-bold tracking-tight">
                                        Crew departments pool
                                    </CardTitle>
                                    <CardDescription className="max-w-xl text-sm leading-relaxed">
                                        Control which employees are available in
                                        the planning sidebar and assignment
                                        picker.
                                    </CardDescription>
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-2 self-start rounded-full border border-border/70 bg-background/60 px-3 py-1.5 text-xs font-semibold shadow-xs">
                                <span className="h-1.5 w-1.5 rounded-full bg-primary" />
                                {selectionLabel}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-5 p-5 sm:p-6">
                        <div className="rounded-xl border border-primary/15 bg-primary/[0.05] p-4">
                            <p className="text-sm font-semibold text-foreground">
                                How department filtering works
                            </p>
                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                Selecting a parent includes every child
                                department. Leave the selection empty to make
                                every active employee available to Crew
                                Planning.
                            </p>
                        </div>

                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col gap-3 rounded-xl border border-border/60 bg-muted/20 p-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-muted-foreground/80 uppercase">
                                        Available departments
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {allDepartmentIds.length} active across
                                        your organization
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-8 rounded-lg bg-background/50 px-3 text-xs"
                                        disabled={allDepartmentIds.length === 0}
                                        onClick={() =>
                                            form.setData(
                                                'pool_department_ids',
                                                allDepartmentIds,
                                            )
                                        }
                                    >
                                        Select all
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-8 rounded-lg px-3 text-xs"
                                        onClick={() =>
                                            form.setData(
                                                'pool_department_ids',
                                                [],
                                            )
                                        }
                                    >
                                        Clear
                                    </Button>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 rounded-xl border border-dashed border-border/80 px-4 py-3 text-xs text-muted-foreground">
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <Check className="h-3.5 w-3.5" />
                                </span>
                                <span>
                                    {noneSelected
                                        ? 'No filter applied — every active employee is available.'
                                        : allSelected
                                          ? 'Every department is selected — all active employees are available.'
                                          : 'A department filter is active. Parent departments include all of their children.'}
                                </span>
                            </div>

                            {department_tree.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground/80">
                                    No active departments found for this
                                    company.
                                </p>
                            ) : (
                                <div className="grid items-start gap-3 md:grid-cols-2">
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

                <div className="space-y-4 lg:sticky lg:top-6">
                    <Card className="overflow-hidden border-border/80 bg-card/70 shadow-sm backdrop-blur-md dark:border-white/8 dark:bg-white/[0.03]">
                        <CardHeader className="border-b border-border/60 bg-linear-to-br from-primary/[0.07] to-transparent p-5 dark:border-white/6">
                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
                                    <Home className="h-5 w-5" />
                                </div>
                                <div className="space-y-1">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Availability rule
                                    </CardTitle>
                                    <CardDescription className="text-xs leading-relaxed">
                                        Define when home-based crew should
                                        return to the deployment pool.
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4 p-5">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="max_home_days"
                                    className="text-sm font-semibold text-foreground"
                                >
                                    Default home stay limit
                                </Label>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    Maximum time at home before a crew member is
                                    expected to be ready for deployment.
                                </p>
                            </div>
                            <div className="space-y-2">
                                <div className="relative">
                                    <Input
                                        id="max_home_days"
                                        type="number"
                                        min="0"
                                        value={form.data.max_home_days}
                                        onChange={(e) =>
                                            form.setData(
                                                'max_home_days',
                                                e.target.value === ''
                                                    ? 0
                                                    : Number(e.target.value),
                                            )
                                        }
                                        className={cn(
                                            'h-12 pr-16 text-lg font-semibold tabular-nums',
                                            form.errors.max_home_days &&
                                                'border-destructive focus-visible:ring-destructive/50',
                                        )}
                                    />
                                    <span className="pointer-events-none absolute inset-y-0 right-4 flex items-center text-xs font-semibold text-muted-foreground">
                                        days
                                    </span>
                                </div>
                                {form.errors.max_home_days ? (
                                    <p className="text-xs font-medium text-destructive">
                                        {form.errors.max_home_days}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex gap-2.5 rounded-xl bg-muted/30 p-3 text-xs leading-relaxed text-muted-foreground">
                                <Clock3 className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                This default applies to every rank unless a
                                specific rule overrides it.
                            </div>
                        </CardContent>
                    </Card>

                    <div className="rounded-2xl border border-border/80 bg-card/70 p-4 shadow-sm backdrop-blur-md dark:border-white/8 dark:bg-white/[0.03]">
                        <div
                            className="mb-4 flex items-start gap-3"
                            aria-live="polite"
                        >
                            <span
                                className={cn(
                                    'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                                    form.isDirty
                                        ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                        : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                                )}
                            >
                                {form.isDirty ? (
                                    <Sliders className="h-4 w-4" />
                                ) : (
                                    <CheckCircle2 className="h-4 w-4" />
                                )}
                            </span>
                            <div>
                                <p className="text-sm font-semibold">
                                    {form.isDirty
                                        ? 'Unsaved changes'
                                        : form.recentlySuccessful
                                          ? 'Settings saved'
                                          : 'Settings are up to date'}
                                </p>
                                <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                    {form.isDirty
                                        ? 'Review and save to apply these defaults.'
                                        : 'Changes will apply across Crew Operations.'}
                                </p>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-2.5">
                            <Button
                                type="button"
                                variant="outline"
                                className="h-10 rounded-xl text-muted-foreground"
                                disabled={form.processing || !form.isDirty}
                                onClick={() => form.reset()}
                            >
                                <RotateCcw className="h-4 w-4" />
                                Reset
                            </Button>
                            <Button
                                type="submit"
                                className="h-10 rounded-xl bg-primary font-semibold shadow-md shadow-primary/10 transition-all hover:bg-primary/95"
                                disabled={form.processing || !form.isDirty}
                            >
                                <Save className="h-4 w-4" />
                                {form.processing ? 'Saving…' : 'Save'}
                            </Button>
                        </div>
                    </div>
                </div>
            </form>
        </Main>
    );
}
