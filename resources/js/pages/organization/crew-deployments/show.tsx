import { Head } from '@inertiajs/react';
import { Activity, Anchor, Calendar, Pencil, User } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { index as deploymentsIndex } from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DeploymentFormDialog } from '@/features/organization/crew-deployments/deployment-form-dialog';
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import { EmployeeProfileLink } from '@/features/organization/crew-deployments/employee-profile-link';
import type {
    DeploymentActivityItem,
    DeploymentItem,
} from '@/features/organization/crew-deployments/types';
import { actions } from '@/lib/design-system';
import { formatDisplayDate, formatDisplayDateTime, formatDisplayValue } from '@/lib/format-date';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { cn } from '@/lib/utils';

type Option = { id: number; name: string };
type EmployeeOption = { id: number; employee_no: string; name: string; rank_id: number | null };

const HIDDEN_ACTIVITY_KEYS = new Set([
    'id',
    'company_id',
    'employee_id',
    'rank_id',
    'client_id',
    'company_visa_type_id',
    'sort_order',
    'created_at',
    'updated_at',
    'deleted_at',
    'redirect_to',
]);

const DEPLOYMENT_FIELD_LABELS: Record<string, string> = {
    vessel_name: 'Vessel',
    arrived_date: 'Arrived',
    join_standby_from: 'Join standby from',
    join_standby_to: 'Join standby to',
    joined_date: 'Joined',
    disembarked_date: 'Disembarked',
    leave_standby_from: 'Leave standby from',
    leave_standby_to: 'Leave standby to',
    travelled_date: 'Travelled',
    remarks: 'Remarks',
};

function titleCaseKey(key: string): string {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (match) => match.toUpperCase());
}

function fieldLabel(key: string): string {
    return DEPLOYMENT_FIELD_LABELS[key] ?? titleCaseKey(key);
}

function changedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null,
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((key) => !HIDDEN_ACTIVITY_KEYS.has(key))
        .sort((a, b) => a.localeCompare(b));
}

function eventColor(event: string | null): string {
    switch (event?.toLowerCase()) {
        case 'created':
            return 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400';
        case 'updated':
            return 'bg-sky-500/10 text-sky-600 border-sky-500/20 dark:text-sky-400';
        case 'deleted':
            return 'bg-red-500/10 text-red-600 border-red-500/20 dark:text-red-400';
        default:
            return 'bg-muted/50 text-muted-foreground border-border dark:bg-white/5 dark:border-white/10';
    }
}

function displayValue(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : '—';
}

function displayNumber(value: number | null | undefined): string {
    return value !== null && value !== undefined ? String(value) : '—';
}

function DetailField({
    label,
    value,
    employeeId,
}: {
    label: string;
    value: string;
    employeeId?: number;
}): ReactElement {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                {label}
            </div>
            <div className="text-right text-sm font-medium">
                {employeeId && value !== '—' ? (
                    <EmployeeProfileLink employeeId={employeeId} className="text-sm">
                        {value}
                    </EmployeeProfileLink>
                ) : (
                    value
                )}
            </div>
        </div>
    );
}

export default function CrewDeploymentShow({
    deployment,
    recent_activity,
    can_view_audit,
    can,
    employees,
    ranks,
    clients,
    company_visa_types,
    back_query,
}: {
    deployment: DeploymentItem;
    recent_activity: DeploymentActivityItem[];
    can_view_audit: boolean;
    can: { manage: boolean };
    employees: EmployeeOption[];
    ranks: Option[];
    clients: Option[];
    company_visa_types: Option[];
    back_query: Record<string, string>;
}): ReactElement {
    const [editOpen, setEditOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

    const backHref = useMemo(
        () => deploymentsIndex.url({ query: back_query }),
        [back_query],
    );

    const pageTitle = [deployment.employee_name, deployment.vessel_name]
        .filter(Boolean)
        .join(' · ') || 'Deployment';

    return (
        <>
            <Head title={pageTitle} />

            <Main>
                <DetailsHeader
                    kicker="Crew Operations"
                    title={
                        deployment.employee_name ? (
                            <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                                <EmployeeProfileLink
                                    employeeId={deployment.employee_id}
                                    className="text-4xl font-extrabold tracking-tight"
                                >
                                    {deployment.employee_name}
                                </EmployeeProfileLink>
                                {deployment.vessel_name ? (
                                    <span className="text-4xl font-extrabold tracking-tight text-foreground">
                                        · {deployment.vessel_name}
                                    </span>
                                ) : null}
                            </span>
                        ) : (
                            pageTitle
                        )
                    }
                    description={`Employee no. ${displayValue(deployment.employee_no)}`}
                    backHref={backHref}
                    backLabel="Back to deployments"
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <DeploymentStatusBadge
                                status={deployment.status}
                                label={deployment.status_label}
                                hint={deployment.status_hint}
                            />
                            {can.manage ? (
                                <Button
                                    type="button"
                                    className={actions.primary}
                                    onClick={() => setEditOpen(true)}
                                >
                                    <Pencil className="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="glass-card lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                            <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                <User className="h-4 w-4" />
                            </div>
                            <CardTitle className="text-lg font-bold tracking-tight">
                                Assignment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y divide-border dark:divide-white/5">
                                <DetailField
                                    label="Employee"
                                    value={displayValue(deployment.employee_name)}
                                    employeeId={deployment.employee_id}
                                />
                                <DetailField
                                    label="Employee no."
                                    value={displayValue(deployment.employee_no)}
                                />
                                <DetailField
                                    label="Nationality"
                                    value={displayValue(deployment.nationality)}
                                />
                                <DetailField label="Rank" value={displayValue(deployment.rank_name)} />
                                <DetailField label="Vessel" value={displayValue(deployment.vessel_name)} />
                                <DetailField
                                    label="Sponsor"
                                    value={displayValue(deployment.company_visa_type_name)}
                                />
                                <DetailField label="Client" value={displayValue(deployment.client_name)} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                            <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                <Anchor className="h-4 w-4" />
                            </div>
                            <CardTitle className="text-lg font-bold tracking-tight">Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 p-6">
                            <DeploymentStatusBadge
                                status={deployment.status}
                                label={deployment.status_label}
                                hint={deployment.status_hint}
                            />
                            <div className="space-y-2 text-sm text-muted-foreground">
                                <div>
                                    Created: {formatDisplayDateTime(deployment.created_at)}
                                </div>
                                <div>
                                    Updated: {formatDisplayDateTime(deployment.updated_at)}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card className="glass-card mt-6 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <Calendar className="h-4 w-4" />
                        </div>
                        <CardTitle className="text-lg font-bold tracking-tight">Timeline</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <DetailField
                                label="Date of hire"
                                value={formatIsoDateDisplay(deployment.hire_date)}
                            />
                            <DetailField
                                label="Arrived"
                                value={formatIsoDateDisplay(deployment.arrived_date)}
                            />
                            <DetailField
                                label="Join standby from"
                                value={formatIsoDateDisplay(deployment.join_standby_from)}
                            />
                            <DetailField
                                label="Join standby to"
                                value={formatIsoDateDisplay(deployment.join_standby_to)}
                            />
                            <DetailField
                                label="Join standby days"
                                value={displayNumber(deployment.join_standby_days)}
                            />
                            <DetailField
                                label="Joined"
                                value={formatIsoDateDisplay(deployment.joined_date)}
                            />
                            <DetailField
                                label="Disembarked"
                                value={formatIsoDateDisplay(deployment.disembarked_date)}
                            />
                            <DetailField
                                label="Vessel days"
                                value={displayNumber(deployment.vessel_days)}
                            />
                            <DetailField
                                label="Leave standby from"
                                value={formatIsoDateDisplay(deployment.leave_standby_from)}
                            />
                            <DetailField
                                label="Leave standby to"
                                value={formatIsoDateDisplay(deployment.leave_standby_to)}
                            />
                            <DetailField
                                label="Leave standby days"
                                value={displayNumber(deployment.leave_standby_days)}
                            />
                            <DetailField
                                label="Travelled"
                                value={formatIsoDateDisplay(deployment.travelled_date)}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card mt-6 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-lg font-bold tracking-tight">Remarks</CardTitle>
                    </CardHeader>
                    <CardContent className="px-6 pb-6">
                        <p className="whitespace-pre-wrap text-sm text-foreground/90">
                            {displayValue(deployment.remarks)}
                        </p>
                    </CardContent>
                </Card>

                {can_view_audit ? (
                    <Card className="glass-card mt-8 dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-center justify-between border-b border-border pb-4 dark:border-white/5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <Activity className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Recent activity
                                    </CardTitle>
                                    <div className="text-[10px] text-muted-foreground/50">
                                        Date and assignment changes for this deployment.
                                    </div>
                                </div>
                            </div>
                            <Badge className="border-border bg-muted/50 font-mono text-xs text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                {recent_activity.length}
                            </Badge>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recent_activity.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 dark:border-white/10 dark:bg-white/[0.03]">
                                        <Activity className="h-5 w-5 text-muted-foreground/20" />
                                    </div>
                                    <p className="text-sm text-muted-foreground/50">
                                        No activity recorded yet.
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y divide-border dark:divide-white/5">
                                    {recent_activity.map((activity) => {
                                        const keys = changedKeys(
                                            activity.old_values,
                                            activity.new_values,
                                        );
                                        const isExpanded = expandedActivity[activity.id] ?? false;
                                        const shown = isExpanded ? keys : keys.slice(0, 4);
                                        const showDescription =
                                            (activity.description ?? '')
                                                .trim()
                                                .toLowerCase() !==
                                            (activity.event ?? '').trim().toLowerCase();

                                        return (
                                            <div
                                                key={activity.id}
                                                className="px-6 py-4 transition-colors hover:bg-muted/30 dark:hover:bg-white/[0.015]"
                                            >
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="min-w-0 flex-1 space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge
                                                                className={cn(
                                                                    'border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                                                    eventColor(activity.event),
                                                                )}
                                                            >
                                                                {activity.event ?? 'event'}
                                                            </Badge>
                                                            <span className="text-sm font-semibold text-foreground/90">
                                                                {activity.causer?.name ?? 'System'}
                                                            </span>
                                                            {activity.causer?.email ? (
                                                                <span className="text-xs text-muted-foreground/50">
                                                                    ({activity.causer.email})
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {showDescription && activity.description ? (
                                                            <p className="text-xs text-muted-foreground/70">
                                                                {activity.description}
                                                            </p>
                                                        ) : null}

                                                        {shown.length > 0 ? (
                                                            <div className="flex flex-wrap gap-1.5 pt-0.5">
                                                                {shown.map((key) => (
                                                                    <span
                                                                        key={key}
                                                                        className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-white/5"
                                                                    >
                                                                        {fieldLabel(key)}:{' '}
                                                                        <span className="text-muted-foreground/70">
                                                                            {formatDisplayValue(
                                                                                activity.old_values?.[key],
                                                                            )}
                                                                        </span>{' '}
                                                                        →{' '}
                                                                        <span className="text-foreground/90">
                                                                            {formatDisplayValue(
                                                                                activity.new_values?.[key],
                                                                            )}
                                                                        </span>
                                                                    </span>
                                                                ))}
                                                                {keys.length > 4 ? (
                                                                    <button
                                                                        type="button"
                                                                        className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground transition hover:bg-accent dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                                                                        onClick={() =>
                                                                            setExpandedActivity((prev) => ({
                                                                                ...prev,
                                                                                [activity.id]: !(
                                                                                    prev[activity.id] ?? false
                                                                                ),
                                                                            }))
                                                                        }
                                                                    >
                                                                        {isExpanded
                                                                            ? 'Show less'
                                                                            : `+${keys.length - 4} more`}
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    <div className="shrink-0 text-xs text-muted-foreground/50">
                                                        {formatDisplayDateTime(activity.created_at)}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                <DeploymentFormDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    editing={deployment}
                    employees={employees}
                    ranks={ranks}
                    clients={clients}
                    companyVisaTypes={company_visa_types}
                    redirectToShow
                />
            </Main>
        </>
    );
}
