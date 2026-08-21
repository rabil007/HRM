import { Head, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Check,
    CheckCircle2,
    ChevronRight,
    Clock3,
    Home,
    Mail,
    RotateCcw,
    Save,
    Ship,
    Sliders,
    X,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { update as updateSettings } from '@/actions/App/Http/Controllers/Organization/CrewOperationsSettingsController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    applyDepartmentToggle,
    flattenDepartmentTreeIds,
    getDepartmentCheckState,
} from '@/features/organization/crew-planning/lib/department-tree';
import type {
    NotificationUserOption,
    PlanningDepartmentNode,
    PlanningSettings,
} from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

type FormData = PlanningSettings;

type Props = {
    department_tree: PlanningDepartmentNode[];
    crew_settings: PlanningSettings;
    notification_users: NotificationUserOption[];
    company_timezone?: string;
};

const ALERT_TYPE_FIELDS = [
    {
        key: 'alert_signoff_overdue' as const,
        label: 'Sign-off overdue',
    },
    {
        key: 'alert_signoff_no_relief' as const,
        label: 'Sign-off approaching — no relief',
    },
    {
        key: 'alert_relief_not_ready' as const,
        label: 'Relief not ready',
    },
    {
        key: 'alert_current_manning_gap' as const,
        label: 'Current manning gap',
    },
    {
        key: 'alert_projected_manning_gap' as const,
        label: 'Projected manning gap',
    },
];

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
    notification_users,
    company_timezone,
}: Props): ReactElement {
    const form = useForm<FormData>({
        ...crew_settings,
    });
    const [disableSyncDialogOpen, setDisableSyncDialogOpen] = useState(false);

    useEffect(() => {
        form.setData({ ...crew_settings });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [crew_settings]);

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
                <div className="space-y-6">
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
                                            Control which employees are
                                            available in the planning sidebar
                                            and assignment picker.
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
                                    department. Leave the selection empty to
                                    make every active employee available to Crew
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
                                            {allDepartmentIds.length} active
                                            across your organization
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-8 rounded-lg bg-background/50 px-3 text-xs"
                                            disabled={
                                                allDepartmentIds.length === 0
                                            }
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

                    <Card className="overflow-hidden border-border/80 bg-card/70 shadow-sm backdrop-blur-md dark:border-white/8 dark:bg-white/[0.03]">
                        <CardHeader className="border-b border-border/60 bg-linear-to-r from-primary/[0.07] via-muted/20 to-transparent p-5 sm:p-6 dark:border-white/6">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div className="flex items-start gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
                                        <Bell className="h-5 w-5" />
                                    </div>
                                    <div className="space-y-1">
                                        <CardTitle className="text-lg font-bold tracking-tight">
                                            Notifications
                                        </CardTitle>
                                        <CardDescription className="max-w-2xl text-sm leading-relaxed">
                                            Choose who receives Crew operational
                                            alerts and which conditions are
                                            tracked. Browser push follows each
                                            user&apos;s existing device
                                            notification preference once inbox
                                            delivery is enabled.
                                        </CardDescription>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3 self-start rounded-full border border-border/70 bg-background/60 px-3 py-1.5 shadow-xs">
                                    <Label
                                        htmlFor="notifications_enabled"
                                        className="text-xs font-semibold"
                                    >
                                        Crew Notifications
                                    </Label>
                                    <Switch
                                        id="notifications_enabled"
                                        checked={
                                            form.data.notifications_enabled
                                        }
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'notifications_enabled',
                                                checked,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-6 p-5 sm:p-6 md:grid-cols-2">
                            {!form.data.notifications_enabled ? (
                                <Alert className="border-amber-500/30 bg-amber-500/10 text-amber-950 md:col-span-2 dark:text-amber-100">
                                    <AlertTriangle className="text-amber-600 dark:text-amber-400" />
                                    <AlertTitle>Notifications off</AlertTitle>
                                    <AlertDescription>
                                        Crew operational alerts are disabled for
                                        this company.
                                    </AlertDescription>
                                </Alert>
                            ) : null}

                            <div className="space-y-3 md:col-span-1">
                                <div>
                                    <p className="text-sm font-semibold">
                                        Recipients
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Active users with membership in this
                                        company.
                                    </p>
                                </div>
                                {form.data.notification_recipient_user_ids
                                    .length > 0 ? (
                                    <div className="flex flex-wrap gap-2">
                                        {form.data.notification_recipient_user_ids.map(
                                            (userId) => {
                                                const user =
                                                    notification_users.find(
                                                        (option) =>
                                                            option.id ===
                                                            userId,
                                                    );

                                                if (!user) {
                                                    return null;
                                                }

                                                return (
                                                    <Badge
                                                        key={user.id}
                                                        variant="secondary"
                                                        className="gap-1 pr-1"
                                                    >
                                                        {user.name}
                                                        <button
                                                            type="button"
                                                            className="rounded-full p-0.5 hover:bg-muted"
                                                            aria-label={`Remove ${user.name}`}
                                                            onClick={() =>
                                                                form.setData(
                                                                    'notification_recipient_user_ids',
                                                                    form.data.notification_recipient_user_ids.filter(
                                                                        (id) =>
                                                                            id !==
                                                                            user.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <X className="h-3 w-3" />
                                                        </button>
                                                    </Badge>
                                                );
                                            },
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        No recipients selected.
                                    </p>
                                )}
                                <div className="max-h-56 space-y-2 overflow-y-auto rounded-xl border border-border/60 bg-muted/10 p-3">
                                    {notification_users.length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            No active company users available.
                                        </p>
                                    ) : (
                                        notification_users.map((user) => {
                                            const checked =
                                                form.data.notification_recipient_user_ids.includes(
                                                    user.id,
                                                );

                                            return (
                                                <label
                                                    key={user.id}
                                                    className="flex cursor-pointer items-start gap-3 rounded-lg px-2 py-1.5 hover:bg-muted/40"
                                                >
                                                    <Checkbox
                                                        checked={checked}
                                                        onCheckedChange={(
                                                            value,
                                                        ) => {
                                                            const next =
                                                                value === true
                                                                    ? [
                                                                          ...form
                                                                              .data
                                                                              .notification_recipient_user_ids,
                                                                          user.id,
                                                                      ]
                                                                    : form.data.notification_recipient_user_ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              user.id,
                                                                      );

                                                            form.setData(
                                                                'notification_recipient_user_ids',
                                                                Array.from(
                                                                    new Set(
                                                                        next,
                                                                    ),
                                                                ),
                                                            );
                                                        }}
                                                    />
                                                    <span className="min-w-0">
                                                        <span className="block truncate text-sm font-medium">
                                                            {user.name}
                                                        </span>
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {user.email}
                                                        </span>
                                                    </span>
                                                </label>
                                            );
                                        })
                                    )}
                                </div>
                                <InputError
                                    message={
                                        form.errors
                                            .notification_recipient_user_ids
                                    }
                                />
                            </div>

                            <div className="space-y-3">
                                <div>
                                    <p className="text-sm font-semibold">
                                        Alert Types
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Enable only the conditions this company
                                        wants to track.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    {ALERT_TYPE_FIELDS.map((field) => (
                                        <label
                                            key={field.key}
                                            className="flex items-center justify-between gap-3 rounded-lg border border-border/60 px-3 py-2"
                                        >
                                            <span className="text-sm">
                                                {field.label}
                                            </span>
                                            <Switch
                                                checked={form.data[field.key]}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        field.key,
                                                        checked,
                                                    )
                                                }
                                            />
                                        </label>
                                    ))}
                                </div>
                            </div>

                            <div className="space-y-4 rounded-xl border border-border/70 bg-muted/15 p-4 md:col-span-2">
                                <div className="flex items-center gap-2.5">
                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-primary/15 bg-primary/10 text-primary">
                                        <Mail className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <h4 className="text-sm font-semibold tracking-tight">
                                            Email Delivery
                                        </h4>
                                        <p className="text-xs text-muted-foreground">
                                            Configure delivery timing and digest
                                            preferences for operational alert
                                            emails.
                                        </p>
                                    </div>
                                </div>

                                <div className="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="notification_email_delivery_mode"
                                            className="text-xs font-semibold"
                                        >
                                            Delivery
                                        </Label>
                                        <Select
                                            value={
                                                form.data
                                                    .notification_email_delivery_mode
                                            }
                                            onValueChange={(
                                                val: 'scheduled' | 'immediate',
                                            ) =>
                                                form.setData(
                                                    'notification_email_delivery_mode',
                                                    val,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="notification_email_delivery_mode"
                                                className="h-10 bg-background/80"
                                            >
                                                <SelectValue placeholder="Select delivery mode" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="scheduled">
                                                    Scheduled digest
                                                </SelectItem>
                                                <SelectItem value="immediate">
                                                    Immediate
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                form.errors
                                                    .notification_email_delivery_mode
                                            }
                                        />
                                    </div>

                                    {form.data
                                        .notification_email_delivery_mode ===
                                    'scheduled' ? (
                                        <div className="space-y-1.5">
                                            <Label
                                                htmlFor="notification_email_digest_at"
                                                className="text-xs font-semibold"
                                            >
                                                Daily digest time
                                            </Label>
                                            <Input
                                                id="notification_email_digest_at"
                                                type="time"
                                                value={
                                                    form.data
                                                        .notification_email_digest_at
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'notification_email_digest_at',
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-10 bg-background/80 font-medium"
                                            />
                                            <InputError
                                                message={
                                                    form.errors
                                                        .notification_email_digest_at
                                                }
                                            />
                                        </div>
                                    ) : null}

                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold text-muted-foreground">
                                            Timezone
                                        </Label>
                                        <div className="flex h-10 items-center rounded-md border border-border/60 bg-muted/40 px-3 text-xs font-medium text-foreground">
                                            {company_timezone || 'UTC'}
                                        </div>
                                        <p className="text-[11px] text-muted-foreground">
                                            Resolved from company timezone.
                                        </p>
                                    </div>
                                </div>

                                <div className="pt-1">
                                    <label className="flex cursor-pointer items-center gap-3 select-none">
                                        <Checkbox
                                            id="notification_email_critical_immediate"
                                            checked={
                                                form.data
                                                    .notification_email_critical_immediate
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'notification_email_critical_immediate',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span className="text-xs font-medium text-foreground">
                                            Send critical alerts immediately
                                        </span>
                                    </label>
                                    <InputError
                                        message={
                                            form.errors
                                                .notification_email_critical_immediate
                                        }
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4 lg:sticky lg:top-6">
                    <Card className="overflow-hidden border-border/80 bg-card/70 shadow-sm backdrop-blur-md dark:border-white/8 dark:bg-white/[0.03]">
                        <CardHeader className="border-b border-border/60 bg-linear-to-br from-primary/[0.07] to-transparent p-5 dark:border-white/6">
                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
                                    <Ship className="h-5 w-5" />
                                </div>
                                <div className="space-y-1">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Assignment Settings
                                    </CardTitle>
                                    <CardDescription className="text-xs leading-relaxed">
                                        Control how crew assignments interact
                                        with Sea Service records.
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4 p-5">
                            <div className="flex items-start justify-between gap-4">
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="sync_sea_service"
                                        className="text-sm font-semibold text-foreground"
                                    >
                                        Sync Sea Service from Crew Assignments
                                    </Label>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        When enabled, crew assignment changes
                                        automatically create or update the crew
                                        member&apos;s Sea Service record.
                                    </p>
                                </div>
                                <Switch
                                    id="sync_sea_service"
                                    checked={form.data.sync_sea_service}
                                    onCheckedChange={(checked) => {
                                        if (
                                            form.data.sync_sea_service &&
                                            !checked
                                        ) {
                                            setDisableSyncDialogOpen(true);

                                            return;
                                        }

                                        form.setData(
                                            'sync_sea_service',
                                            checked,
                                        );
                                    }}
                                />
                            </div>
                            {form.errors.sync_sea_service ? (
                                <p className="text-xs font-medium text-destructive">
                                    {form.errors.sync_sea_service}
                                </p>
                            ) : null}
                            {!form.data.sync_sea_service ? (
                                <Alert className="border-amber-500/30 bg-amber-500/10 text-amber-950 dark:text-amber-100">
                                    <AlertTriangle className="text-amber-600 dark:text-amber-400" />
                                    <AlertTitle>Sync disabled</AlertTitle>
                                    <AlertDescription>
                                        Sea Service synchronization is disabled.
                                        Crew assignments will not automatically
                                        update Sea Service records.
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                        </CardContent>
                    </Card>

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

            <ConfirmDeleteDialog
                open={disableSyncDialogOpen}
                onOpenChange={setDisableSyncDialogOpen}
                title="Disable Sea Service sync?"
                description="Disabling Sea Service synchronization will stop future crew assignment changes from updating Sea Service records. Existing records will not be affected."
                cancelText="Cancel"
                confirmText="Disable Sync"
                onConfirm={() => {
                    form.setData('sync_sea_service', false);
                    setDisableSyncDialogOpen(false);
                }}
            />
        </Main>
    );
}
