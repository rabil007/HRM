import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Calendar,
    CheckCircle2,
    CircleDot,
    Copy,
    FileText,
    Mail,
    MapPin,
    UserCheck,
    Users,
} from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    RequirementDetail,
    RequirementWorkflowTimeline,
} from '@/types/recruitment';

type Props = {
    requirement: RequirementDetail;
    timeline: RequirementWorkflowTimeline;
};

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="space-y-1">
            <div className="text-[11px] font-bold tracking-wider text-muted-foreground/80 uppercase">
                {label}
            </div>
            <div className="text-sm font-medium break-words text-foreground">
                {value || '—'}
            </div>
        </div>
    );
}

function WorkflowTimelineSection({
    timeline,
}: {
    timeline: RequirementWorkflowTimeline;
}) {
    return (
        <div
            data-requirement-workflow-section
            className="space-y-4 lg:border-l lg:border-border/50 lg:pl-6"
        >
            <div className="space-y-2">
                <h3 className="text-sm font-semibold text-foreground">
                    Workflow timeline
                </h3>
                <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-xs">
                    <p className="text-muted-foreground">
                        Current stage:{' '}
                        <span className="font-semibold text-foreground">
                            {timeline.current_stage_label}
                        </span>
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Next expected action:{' '}
                        <span className="font-semibold text-foreground">
                            {timeline.next_expected_action_label ?? 'None'}
                        </span>
                    </p>
                </div>
            </div>

            {timeline.events.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No workflow events recorded yet.
                </p>
            ) : (
                <ol className="relative space-y-4 border-l border-border/70 pl-4">
                    {timeline.events.map((event) => {
                        const Icon =
                            event.state === 'current'
                                ? CircleDot
                                : CheckCircle2;

                        return (
                            <li key={event.id} className="relative">
                                <span
                                    className={cn(
                                        'absolute top-0.5 -left-[1.35rem] flex h-4 w-4 items-center justify-center rounded-full bg-background',
                                        event.state === 'current'
                                            ? 'text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <Icon
                                        className="h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div
                                    className={cn(
                                        'rounded-lg border px-3 py-2',
                                        event.state === 'current'
                                            ? 'border-primary/30 bg-primary/5'
                                            : 'border-border/60 bg-muted/20',
                                    )}
                                >
                                    <p className="text-sm font-medium text-foreground">
                                        {event.label}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                                        {event.occurred_at_formatted}
                                        {event.actor_name
                                            ? ` · ${event.actor_name}`
                                            : ''}
                                    </p>
                                    {event.reason ? (
                                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                            {event.reason}
                                        </p>
                                    ) : null}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}

export function RequirementDetailsWorkflowCard({
    requirement,
    timeline,
}: Props) {
    return (
        <Card
            data-requirement-details-workflow-card
            className="glass-card border-border/70"
        >
            <CardHeader className="border-b border-border/40 pb-4">
                <CardTitle className="flex items-center gap-2 text-base font-bold">
                    <FileText className="h-4 w-4 text-primary" />
                    Requirement Details & Workflow
                </CardTitle>
            </CardHeader>
            <CardContent className="p-6">
                <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
                    <div data-requirement-details-section className="space-y-6">
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
                                <div className="flex items-center gap-2 rounded-lg border border-border/80 bg-muted/30 p-3 text-xs">
                                    <Copy className="h-4 w-4 text-primary" />
                                    <span className="text-muted-foreground">
                                        This requirement was cloned and repeated
                                        from:
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

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <DetailRow
                                label="Client"
                                value={
                                    <div className="flex items-center gap-1.5">
                                        <Building2 className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                        <span>{requirement.client_name}</span>
                                    </div>
                                }
                            />

                            <DetailRow
                                label="Project / Site"
                                value={requirement.project_title || 'General'}
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

                            <DetailRow
                                label="Location / Base"
                                value={
                                    requirement.location ? (
                                        <div className="flex items-center gap-1.5">
                                            <MapPin className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <span>{requirement.location}</span>
                                        </div>
                                    ) : null
                                }
                            />

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
                                            {
                                                requirement.required_by_date_formatted
                                            }
                                        </span>
                                    </div>
                                }
                            />

                            <DetailRow
                                label="Assigned Recruiter"
                                value={
                                    requirement.assigned_recruiter_name ? (
                                        <div className="flex items-center gap-1.5">
                                            <UserCheck className="h-3.5 w-3.5 shrink-0 text-primary" />
                                            <span>
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

                        {requirement.notification_recipients.length > 0 ? (
                            <div className="space-y-2 border-t border-border/40 pt-4">
                                <div className="flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-muted-foreground/80 uppercase">
                                    <Users className="h-3.5 w-3.5" />
                                    Notification recipients (CC)
                                </div>
                                <ul className="space-y-2">
                                    {requirement.notification_recipients.map(
                                        (recipient) => (
                                            <li
                                                key={recipient.id}
                                                className="flex items-start gap-2 rounded-lg border border-border/60 bg-muted/20 px-3 py-2 text-xs"
                                            >
                                                <Mail className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                                <span>
                                                    <span className="font-medium text-foreground">
                                                        {recipient.name}
                                                    </span>
                                                    <span className="block break-all text-muted-foreground">
                                                        {recipient.email}
                                                    </span>
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : null}

                        {requirement.notes ? (
                            <div className="space-y-2 border-t border-border/40 pt-4">
                                <div className="text-[11px] font-bold tracking-wider text-muted-foreground/80 uppercase">
                                    Notes / Scope of Work
                                </div>
                                <p className="rounded-xl border border-border/60 bg-muted/20 p-4 text-xs leading-relaxed whitespace-pre-wrap text-foreground">
                                    {requirement.notes}
                                </p>
                            </div>
                        ) : null}

                        <div className="grid grid-cols-1 gap-4 border-t border-border/40 pt-4 sm:grid-cols-2">
                            <DetailRow
                                label="Created At"
                                value={requirement.created_at_formatted}
                            />
                            <DetailRow
                                label="Created By"
                                value={requirement.creator_name}
                            />
                            <DetailRow
                                label="Last Updated By"
                                value={requirement.updater_name}
                            />
                        </div>
                    </div>

                    <WorkflowTimelineSection timeline={timeline} />
                </div>
            </CardContent>
        </Card>
    );
}
