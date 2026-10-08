import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    FileText,
    FolderKanban,
    Mail,
    MapPin,
    Timer,
    UserCheck,
    Users,
    XCircle,
} from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { RequirementDetail } from '@/types/recruitment';
import { resolveActiveRecruitmentDurationDisplay } from '../../lib/requirement-recruitment-clock';
import { RequirementDeadlineBadge } from '../requirement-status-badge';

type Props = {
    requirement: RequirementDetail;
};

function SectionHeading({
    children,
    icon,
}: {
    children: React.ReactNode;
    icon?: React.ReactNode;
}) {
    return (
        <h3 className="flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-muted-foreground/80 uppercase">
            {icon}
            <span>{children}</span>
        </h3>
    );
}

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="min-w-0 space-y-1">
            <div className="text-[11px] font-medium text-muted-foreground">
                {label}
            </div>
            <div className="text-sm font-medium break-words text-foreground">
                {value || '—'}
            </div>
        </div>
    );
}

function InfoPanel({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-border/60 bg-muted/15 p-4',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function RequirementDetailsWorkflowCard({ requirement }: Props) {
    const activeRecruitment = resolveActiveRecruitmentDurationDisplay({
        clockState: requirement.recruitment_clock_state,
        activeSeconds: requirement.active_recruitment_seconds,
        isEstimated: requirement.duration_is_estimated,
        estimateNote: requirement.duration_estimate_note,
    });
    const daysRemainingLabel =
        requirement.required_by_date !== null
            ? (requirement.days_label ?? '—')
            : 'No deadline';

    return (
        <Card
            data-requirement-details-workflow-card
            className="glass-card border-border/70"
        >
            <CardHeader className="border-b border-border/40 pb-4">
                <CardTitle className="flex items-center gap-2 text-base font-bold">
                    <FileText className="h-4 w-4 text-primary" />
                    Requirement Details
                </CardTitle>
            </CardHeader>
            <CardContent className="p-5 sm:p-6">
                <div data-requirement-details-section className="space-y-5">
                    {requirement.status === 'returned' && (
                        <div className="space-y-1 rounded-xl border border-orange-500/30 bg-orange-500/[0.06] p-4 text-xs text-orange-900 dark:text-orange-200">
                            <div className="flex items-center gap-1.5 font-bold text-orange-600 dark:text-orange-400">
                                <AlertTriangle className="h-4 w-4" />
                                <span>Returned for revision</span>
                            </div>
                            <p className="pl-5 leading-relaxed">
                                {requirement.return_reason ||
                                    'No return reason specified.'}
                            </p>
                            {requirement.returned_at_formatted && (
                                <div className="pl-5 text-[11px] text-muted-foreground">
                                    Returned on:{' '}
                                    {requirement.returned_at_formatted}
                                    {requirement.returner_name
                                        ? ` by ${requirement.returner_name}`
                                        : ''}
                                </div>
                            )}
                        </div>
                    )}

                    {requirement.submission_readiness &&
                        (requirement.status === 'draft' ||
                            requirement.status === 'returned') && (
                            <div
                                data-requirement-submission-readiness
                                className="space-y-3 rounded-xl border border-border/70 bg-muted/20 p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <SectionHeading>
                                            Submission readiness
                                        </SectionHeading>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {requirement.submission_readiness
                                                .ready
                                                ? 'All required information is complete.'
                                                : `${requirement.submission_readiness.remaining_count} item${requirement.submission_readiness.remaining_count === 1 ? '' : 's'} remaining`}
                                        </p>
                                    </div>
                                    {requirement.submission_readiness.ready ? (
                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                            Ready
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                                            <AlertTriangle className="h-3.5 w-3.5" />
                                            Incomplete
                                        </span>
                                    )}
                                </div>
                                <ul className="space-y-1.5">
                                    {requirement.submission_readiness.items.map(
                                        (entry) => (
                                            <li
                                                key={entry.key}
                                                className="flex items-start gap-2 text-xs"
                                            >
                                                {entry.ready ? (
                                                    <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                                                ) : (
                                                    <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-rose-500" />
                                                )}
                                                <span
                                                    className={
                                                        entry.ready
                                                            ? 'text-muted-foreground'
                                                            : 'font-medium text-foreground'
                                                    }
                                                >
                                                    {entry.label}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}

                    {requirement.status === 'cancelled' && (
                        <div className="space-y-1 rounded-xl border border-rose-500/30 bg-rose-500/[0.06] p-4 text-xs text-rose-900 dark:text-rose-200">
                            <div className="flex items-center gap-1.5 font-bold text-rose-600 dark:text-rose-400">
                                <AlertTriangle className="h-4 w-4" />
                                <span>Cancellation Notice</span>
                            </div>
                            <p className="pl-5 leading-relaxed">
                                {requirement.cancellation_reason ||
                                    'No cancellation reason specified.'}
                            </p>
                            {requirement.cancelled_at_formatted && (
                                <div className="pl-5 text-[11px] text-muted-foreground">
                                    Cancelled on:{' '}
                                    {requirement.cancelled_at_formatted}
                                </div>
                            )}
                        </div>
                    )}

                    {requirement.repeated_from_id &&
                        requirement.repeated_from_number && (
                            <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border/80 bg-muted/30 px-3 py-2.5 text-xs">
                                <Copy className="h-4 w-4 shrink-0 text-primary" />
                                <span className="text-muted-foreground">
                                    Repeated from
                                </span>
                                <Link
                                    href={RequirementController.show.url(
                                        requirement.repeated_from_id,
                                    )}
                                    className="font-mono font-bold text-primary hover:underline"
                                >
                                    {requirement.repeated_from_number}
                                </Link>
                            </div>
                        )}

                    <section className="space-y-3">
                        <SectionHeading
                            icon={
                                <Building2
                                    className="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                            }
                        >
                            Client request
                        </SectionHeading>
                        <InfoPanel>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <DetailRow
                                    label="Client"
                                    value={
                                        <div className="flex items-center gap-1.5">
                                            <Building2 className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <span>
                                                {requirement.client_name}
                                            </span>
                                        </div>
                                    }
                                />
                                <DetailRow
                                    label="Project / Site"
                                    value={
                                        <div className="flex items-center gap-1.5">
                                            <FolderKanban className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <span>
                                                {requirement.project_title ||
                                                    'General'}
                                            </span>
                                        </div>
                                    }
                                />
                                <DetailRow
                                    label="Location / Base"
                                    value={
                                        requirement.location ? (
                                            <div className="flex items-center gap-1.5">
                                                <MapPin className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                                <span>
                                                    {requirement.location}
                                                </span>
                                            </div>
                                        ) : null
                                    }
                                />
                                {requirement.has_legacy_client_reference ? (
                                    <DetailRow
                                        label="Client Reference # (legacy)"
                                        value={
                                            requirement.client_reference_number ? (
                                                <span className="font-mono text-xs">
                                                    {
                                                        requirement.client_reference_number
                                                    }
                                                </span>
                                            ) : null
                                        }
                                    />
                                ) : null}
                            </div>
                        </InfoPanel>
                    </section>

                    <section className="space-y-3">
                        <SectionHeading
                            icon={
                                <Calendar
                                    className="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                            }
                        >
                            Schedule & ownership
                        </SectionHeading>

                        <InfoPanel className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <DetailRow
                                    label="Request Received from Client"
                                    value={
                                        <div className="flex items-center gap-1.5">
                                            <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <span>
                                                {
                                                    requirement.request_received_date_formatted
                                                }
                                            </span>
                                        </div>
                                    }
                                />
                                <DetailRow
                                    label="Target Date"
                                    value={
                                        <div className="flex items-center gap-1.5">
                                            <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <span className="font-semibold text-foreground">
                                                {requirement.required_by_date_formatted ||
                                                    'No deadline'}
                                            </span>
                                        </div>
                                    }
                                />
                            </div>

                            <div
                                data-requirement-schedule-metrics
                                className="grid grid-cols-1 gap-3 border-t border-border/50 pt-4 sm:grid-cols-2"
                            >
                                <div
                                    className={cn(
                                        'rounded-lg border p-3',
                                        requirement.deadline_health ===
                                            'overdue'
                                            ? 'border-rose-500/30 bg-rose-500/[0.05]'
                                            : requirement.deadline_health ===
                                                'due_soon'
                                              ? 'border-amber-500/30 bg-amber-500/[0.05]'
                                              : 'border-border/50 bg-background/40',
                                    )}
                                >
                                    <div className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                                        <Clock
                                            className="h-3.5 w-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span>Days remaining</span>
                                    </div>
                                    <div className="mt-2">
                                        {requirement.deadline_health ? (
                                            <RequirementDeadlineBadge
                                                health={
                                                    requirement.deadline_health
                                                }
                                                label={requirement.days_label}
                                                className="text-[13px]"
                                            />
                                        ) : (
                                            <p className="text-sm font-semibold text-foreground">
                                                {daysRemainingLabel}
                                            </p>
                                        )}
                                    </div>
                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                        Countdown to target date
                                    </p>
                                </div>

                                <div
                                    className={cn(
                                        'rounded-lg border p-3',
                                        requirement.recruitment_clock_state ===
                                            'paused'
                                            ? 'border-amber-500/30 bg-amber-500/[0.05]'
                                            : requirement.recruitment_clock_state ===
                                                'running'
                                              ? 'border-sky-500/30 bg-sky-500/[0.05]'
                                              : 'border-border/50 bg-background/40',
                                    )}
                                >
                                    <div className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                                        <Timer
                                            className="h-3.5 w-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span>Active recruitment</span>
                                    </div>
                                    <p className="mt-2 text-sm font-semibold text-foreground tabular-nums">
                                        {activeRecruitment.label}
                                    </p>
                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                        {requirement.approved_at_formatted
                                            ? `Since approval · ${requirement.approved_at_formatted}`
                                            : 'Counts up after recruiter approval'}
                                    </p>
                                    {activeRecruitment.showEstimated &&
                                    activeRecruitment.estimateNote ? (
                                        <p className="mt-1 text-[11px] text-muted-foreground">
                                            {activeRecruitment.estimateNote}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="border-t border-border/50 pt-4">
                                <DetailRow
                                    label="Assigned Recruiter"
                                    value={
                                        requirement.assigned_recruiter_name ? (
                                            <div className="inline-flex max-w-full items-center gap-2 rounded-full border border-primary/20 bg-primary/5 px-2.5 py-1">
                                                <UserCheck className="h-3.5 w-3.5 shrink-0 text-primary" />
                                                <span className="truncate text-sm font-medium">
                                                    {
                                                        requirement.assigned_recruiter_name
                                                    }
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Unassigned
                                            </span>
                                        )
                                    }
                                />
                            </div>
                        </InfoPanel>
                    </section>

                    {requirement.notification_recipients.length > 0 ? (
                        <section className="space-y-3">
                            <SectionHeading
                                icon={
                                    <Users
                                        className="h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                }
                            >
                                Notification recipients (CC)
                            </SectionHeading>
                            <div className="flex flex-wrap gap-2">
                                {requirement.notification_recipients.map(
                                    (recipient) => (
                                        <div
                                            key={recipient.id}
                                            className="inline-flex max-w-full items-start gap-2 rounded-lg border border-border/60 bg-muted/20 px-3 py-2 text-xs"
                                        >
                                            <Mail className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <span className="min-w-0">
                                                <span className="block font-medium text-foreground">
                                                    {recipient.name}
                                                </span>
                                                <span className="block break-all text-muted-foreground">
                                                    {recipient.email}
                                                </span>
                                            </span>
                                        </div>
                                    ),
                                )}
                            </div>
                        </section>
                    ) : null}

                    {requirement.notes ? (
                        <section className="space-y-3">
                            <SectionHeading
                                icon={
                                    <FileText
                                        className="h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                }
                            >
                                Notes / Scope of Work
                            </SectionHeading>
                            <InfoPanel>
                                <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                                    {requirement.notes}
                                </p>
                            </InfoPanel>
                        </section>
                    ) : null}

                    <section className="space-y-3 border-t border-border/40 pt-4">
                        <SectionHeading>Record info</SectionHeading>
                        <div className="grid grid-cols-1 gap-3 text-xs sm:grid-cols-3">
                            <div className="rounded-lg bg-muted/20 px-3 py-2.5">
                                <p className="text-[11px] text-muted-foreground">
                                    Created At
                                </p>
                                <p className="mt-1 font-medium text-foreground">
                                    {requirement.created_at_formatted || '—'}
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/20 px-3 py-2.5">
                                <p className="text-[11px] text-muted-foreground">
                                    Created By
                                </p>
                                <p className="mt-1 font-medium text-foreground">
                                    {requirement.creator_name || '—'}
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/20 px-3 py-2.5">
                                <p className="text-[11px] text-muted-foreground">
                                    Last Updated By
                                </p>
                                <p className="mt-1 font-medium text-foreground">
                                    {requirement.updater_name || '—'}
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </CardContent>
        </Card>
    );
}
