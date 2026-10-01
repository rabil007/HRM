import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Calendar,
    Clock3,
    Copy,
    FileText,
    Mail,
    MapPin,
    UserCheck,
    Users,
} from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RequirementDetail } from '@/types/recruitment';
import { resolveRecruitmentClockSummary } from '../../lib/requirement-recruitment-clock';

type Props = {
    requirement: RequirementDetail;
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
            <div className="text-sm font-medium text-foreground">
                {value || '—'}
            </div>
        </div>
    );
}

export function RequirementDetailsCard({ requirement }: Props) {
    const clockSummary = resolveRecruitmentClockSummary({
        clockState: requirement.recruitment_clock_state,
        durationLabel: requirement.recruitment_duration_label,
        startedAtFormatted: requirement.recruitment_started_at_formatted,
    });

    return (
        <Card className="glass-card border-border/70">
            <CardHeader className="border-b border-border/40 pb-4">
                <CardTitle className="flex items-center gap-2 text-base font-bold">
                    <FileText className="h-4 w-4 text-primary" />
                    Requirement Specifications
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6 p-6">
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
                                Returned on: {requirement.returned_at_formatted}
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
                                This requirement was cloned and repeated from:
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

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailRow
                        label="Client"
                        value={
                            <div className="flex items-center gap-1.5">
                                <Building2 className="h-3.5 w-3.5 text-muted-foreground" />
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
                                        {requirement.client_reference_number}
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
                                    <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                                    <span>{requirement.location}</span>
                                </div>
                            ) : null
                        }
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 border-t border-border/40 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailRow
                        label="Request Received"
                        value={
                            <div className="flex items-center gap-1.5">
                                <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                                <span>
                                    {
                                        requirement.request_received_date_formatted
                                    }
                                </span>
                            </div>
                        }
                    />

                    <DetailRow
                        label="Required-By Date"
                        value={
                            <div className="flex items-center gap-1.5">
                                <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                                <span className="font-semibold text-foreground">
                                    {requirement.required_by_date_formatted}
                                </span>
                            </div>
                        }
                    />

                    <DetailRow
                        label="Assigned Recruiter"
                        value={
                            requirement.assigned_recruiter_name ? (
                                <div className="flex items-center gap-1.5">
                                    <UserCheck className="h-3.5 w-3.5 text-primary" />
                                    <span>
                                        {requirement.assigned_recruiter_name}
                                    </span>
                                </div>
                            ) : (
                                <span className="text-muted-foreground">
                                    Unassigned
                                </span>
                            )
                        }
                    />

                    <DetailRow
                        label="Recruitment clock"
                        value={
                            <div className="flex items-start gap-1.5">
                                <Clock3 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                <span>{clockSummary}</span>
                            </div>
                        }
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 border-t border-border/40 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailRow
                        label="Submitted for approval"
                        value={
                            requirement.submitted_at_formatted
                                ? `${requirement.submitted_at_formatted}${requirement.submitter_name ? ` · ${requirement.submitter_name}` : ''}`
                                : '—'
                        }
                    />
                    <DetailRow
                        label="Approved"
                        value={
                            requirement.approved_at_formatted
                                ? `${requirement.approved_at_formatted}${requirement.approved_by_name ? ` · ${requirement.approved_by_name}` : ''}`
                                : '—'
                        }
                    />
                    <DetailRow
                        label="Opened Date"
                        value={
                            requirement.opened_at_formatted || 'Not yet opened'
                        }
                    />
                    <DetailRow
                        label="Active recruitment"
                        value={
                            requirement.recruitment_duration_label
                                ? requirement.recruitment_duration_label
                                : '—'
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
                                            <span className="block text-muted-foreground">
                                                {recipient.email}
                                            </span>
                                        </span>
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>
                ) : null}

                <div className="grid grid-cols-1 gap-4 border-t border-border/40 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailRow
                        label="Created At"
                        value={requirement.created_at_formatted}
                    />
                    <DetailRow
                        label="Created By"
                        value={requirement.creator_name}
                    />
                    <DetailRow
                        label="Completed At"
                        value={requirement.completed_at_formatted || '—'}
                    />
                    <DetailRow
                        label="Last Updated By"
                        value={requirement.updater_name}
                    />
                </div>

                {requirement.notes && (
                    <div className="space-y-2 border-t border-border/40 pt-4">
                        <div className="text-[11px] font-bold tracking-wider text-muted-foreground/80 uppercase">
                            Notes / Scope of Work
                        </div>
                        <p className="rounded-xl border border-border/60 bg-muted/20 p-4 text-xs leading-relaxed whitespace-pre-wrap text-foreground">
                            {requirement.notes}
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
