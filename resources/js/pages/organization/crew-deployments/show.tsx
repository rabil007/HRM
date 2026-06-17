import { Head } from '@inertiajs/react';
import {
    AlertCircle,
    Anchor,
    Building2,
    Calendar,
    Clock,
    MessageSquare,
    Pencil,
    Ship,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { index as deploymentsIndex } from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DeploymentDetailField } from '@/features/organization/crew-deployments/deployment-detail-field';
import { DeploymentFormDialog } from '@/features/organization/crew-deployments/deployment-form-dialog';
import { DeploymentLifecycleTimeline } from '@/features/organization/crew-deployments/deployment-lifecycle-timeline';
import { DeploymentShowActivity } from '@/features/organization/crew-deployments/deployment-show-activity';
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import { EmployeeProfileLink } from '@/features/organization/crew-deployments/employee-profile-link';
import type {
    DeploymentActivityItem,
    DeploymentItem,
} from '@/features/organization/crew-deployments/types';
import { actions } from '@/lib/design-system';
import { formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';

type Option = { id: number; name: string };
type EmployeeOption = { id: number; employee_no: string; name: string; rank_id: number | null };

function displayValue(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : '—';
}

function displayNumber(value: number | null | undefined): string {
    return value !== null && value !== undefined ? String(value) : '—';
}

function StatChip({
    label,
    value,
    highlight = false,
}: {
    label: string;
    value: string;
    highlight?: boolean;
}): ReactElement {
    return (
        <div
            className={cn(
                'rounded-xl border px-4 py-3',
                highlight
                    ? 'border-primary/30 bg-primary/10'
                    : 'border-border/80 bg-muted/20 dark:border-white/10 dark:bg-white/3',
            )}
        >
            <div className="text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70">
                {label}
            </div>
            <div className="mt-1 text-lg font-bold tracking-tight">{value}</div>
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
    vessels,
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
    vessels: Option[];
    back_query: Record<string, string>;
}): ReactElement {
    const [editOpen, setEditOpen] = useState(false);

    const backHref = useMemo(
        () => deploymentsIndex.url({ query: back_query }),
        [back_query],
    );

    const pageTitle = [deployment.employee_name, deployment.vessel_name]
        .filter(Boolean)
        .join(' · ') || 'Deployment';

    const hasRemarks = Boolean(deployment.remarks?.trim());

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
                    description={`Employee no. ${displayValue(deployment.employee_no)}${deployment.rank_name ? ` · ${deployment.rank_name}` : ''}`}
                    backHref={backHref}
                    backLabel="Back to deployments"
                    actions={
                        can.manage ? (
                            <Button
                                type="button"
                                className={actions.primary}
                                onClick={() => setEditOpen(true)}
                            >
                                <Pencil className="mr-2 h-4 w-4" />
                                Edit deployment
                            </Button>
                        ) : null
                    }
                />

                {deployment.status_hint ? (
                    <div className="mb-6 flex items-start gap-3 rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <div className="font-semibold">Action needed</div>
                            <div className="mt-0.5 text-red-600/90 dark:text-red-200/90">
                                {deployment.status_hint}
                            </div>
                        </div>
                    </div>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="glass-card overflow-hidden lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="border-b border-border pb-5 dark:border-white/5">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div className="flex items-start gap-4">
                                    <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                                        <User className="h-7 w-7" />
                                    </div>
                                    <div className="min-w-0 space-y-2">
                                        <DeploymentStatusBadge
                                            status={deployment.status}
                                            label={deployment.status_label}
                                            hint={deployment.status_hint}
                                        />
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                            {deployment.nationality ? (
                                                <span>{deployment.nationality}</span>
                                            ) : null}
                                            {deployment.vessel_name ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Ship className="h-3.5 w-3.5" />
                                                    {deployment.vessel_name}
                                                </span>
                                            ) : null}
                                            {deployment.current_vessel &&
                                            deployment.current_vessel !== deployment.vessel_name ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Anchor className="h-3.5 w-3.5" />
                                                    Now: {deployment.current_vessel}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                                <div className="grid grid-cols-3 gap-2 sm:max-w-sm">
                                    <StatChip
                                        label="Vessel days"
                                        value={displayNumber(deployment.vessel_days)}
                                        highlight={deployment.status === 'on_vessel'}
                                    />
                                    <StatChip
                                        label="Join SB"
                                        value={displayNumber(deployment.join_standby_days)}
                                        highlight={deployment.status === 'join_standby'}
                                    />
                                    <StatChip
                                        label="Leave SB"
                                        value={displayNumber(deployment.leave_standby_days)}
                                        highlight={deployment.status === 'leave_standby'}
                                    />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y divide-border dark:divide-white/5">
                                <DeploymentDetailField
                                    label="Employee"
                                    value={displayValue(deployment.employee_name)}
                                    employeeId={deployment.employee_id}
                                />
                                <DeploymentDetailField
                                    label="Employee no."
                                    value={displayValue(deployment.employee_no)}
                                />
                                <DeploymentDetailField
                                    label="Rank"
                                    value={displayValue(deployment.rank_name)}
                                />
                                <DeploymentDetailField
                                    label="Sponsor"
                                    value={displayValue(deployment.company_visa_type_name)}
                                />
                                <DeploymentDetailField
                                    label="Client"
                                    value={displayValue(deployment.client_name)}
                                />
                                <DeploymentDetailField
                                    label="Date of hire"
                                    value={formatIsoDateDisplay(deployment.hire_date)}
                                    subdued
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                            <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <Building2 className="h-4 w-4" />
                                </div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Assignment
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 p-6">
                                <div className="rounded-xl border border-border/80 bg-muted/20 p-4 dark:border-white/10 dark:bg-white/3">
                                    <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/70">
                                        Vessel
                                    </div>
                                    <div className="mt-1 text-base font-semibold">
                                        {displayValue(deployment.vessel_name)}
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <div className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                                            Client
                                        </div>
                                        <div className="mt-1 font-medium">
                                            {displayValue(deployment.client_name)}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                                            Sponsor
                                        </div>
                                        <div className="mt-1 font-medium">
                                            {displayValue(deployment.company_visa_type_name)}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                            <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <Clock className="h-4 w-4" />
                                </div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Record
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 p-6 text-sm text-muted-foreground">
                                <div>
                                    <span className="font-medium text-foreground/80">Created</span>
                                    <div>{formatDisplayDateTime(deployment.created_at)}</div>
                                </div>
                                <div>
                                    <span className="font-medium text-foreground/80">Updated</span>
                                    <div>{formatDisplayDateTime(deployment.updated_at)}</div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <Card className="glass-card mt-6 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <Calendar className="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle className="text-lg font-bold tracking-tight">
                                Deployment lifecycle
                            </CardTitle>
                            <p className="text-xs text-muted-foreground">
                                Arrived → join standby → on vessel → leave standby → travelled
                            </p>
                        </div>
                    </CardHeader>
                    <CardContent className="p-6">
                        <DeploymentLifecycleTimeline deployment={deployment} />
                    </CardContent>
                </Card>

                <Card className="glass-card mt-6 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-center gap-3 border-b border-border pb-4 dark:border-white/5">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <MessageSquare className="h-4 w-4" />
                        </div>
                        <CardTitle className="text-lg font-bold tracking-tight">Remarks</CardTitle>
                    </CardHeader>
                    <CardContent className="px-6 pb-6 pt-4">
                        {hasRemarks ? (
                            <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground/90">
                                {deployment.remarks}
                            </p>
                        ) : (
                            <p className="text-sm italic text-muted-foreground/60">
                                No remarks recorded for this deployment.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {can_view_audit ? (
                    <div className="mt-8">
                        <DeploymentShowActivity recentActivity={recent_activity} />
                    </div>
                ) : null}

                <DeploymentFormDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    editing={deployment}
                    employees={employees}
                    ranks={ranks}
                    clients={clients}
                    companyVisaTypes={company_visa_types}
                    vessels={vessels}
                    redirectToShow
                />
            </Main>
        </>
    );
}
