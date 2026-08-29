import { Head, router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import CancelDocumentRecipientRequestController from '@/actions/App/Http/Controllers/Organization/Documents/CancelDocumentRecipientRequestController';
import RegenerateDocumentRecipientRequestTokenController from '@/actions/App/Http/Controllers/Organization/Documents/RegenerateDocumentRecipientRequestTokenController';
import ResendDocumentRecipientRequestEmailController from '@/actions/App/Http/Controllers/Organization/Documents/ResendDocumentRecipientRequestEmailController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import type {
    RecipientReminderSummary,
    RecipientRequestEmailDelivery,
    RecipientRequestPermissions,
    RecipientRequestStatus,
} from '@/features/organization/documents/workflow/types';
import { formatDisplayDate } from '@/lib/format-date';
import documentRoutes from '@/routes/organization/documents';

type RecipientRequestDetail = {
    id: number;
    action: string;
    action_label: string;
    status: RecipientRequestStatus;
    status_label: string;
    recipient_name: string;
    recipient_type: string;
    recipient_type_label: string;
    recipient_role: string;
    recipient_role_label: string;
    is_public_token_recipient: boolean;
    respond_url: string | null;
    email_delivery: RecipientRequestEmailDelivery | null;
    reminder_summary: RecipientReminderSummary | null;
    requested_at: string | null;
    expires_at: string | null;
    first_viewed_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    requested_by: { id: number | null; name: string | null };
    cancelled_by: { id: number | null; name: string | null } | null;
    document: {
        id: number | null;
        title: string | null;
        employee_id: number | null;
    };
    employee: {
        id: number | null;
        name: string | null;
        employee_no: string | null;
    };
    company_signatory: {
        id: number;
        name: string;
    } | null;
    source_version: {
        id: number;
        version: number | null;
        checksum_abbrev: string | null;
    };
    result_version: {
        id: number;
        version: number | null;
        checksum_abbrev: string | null;
    } | null;
    signed_name: string | null;
    acknowledgement_text_snapshot: string | null;
    timeline: Array<{
        event: string;
        occurred_at: string | null;
        actor_name: string | null;
    }>;
};

type Props = {
    recipient_request: RecipientRequestDetail;
    can: RecipientRequestPermissions;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

export default function RecipientRequestShow({
    recipient_request,
    can,
    recent_activity,
    can_view_audit,
}: Props): ReactElement {
    return (
        <>
            <Head title={`${recipient_request.action_label} request`} />
            <Main>
                <DocumentsBreadcrumbs
                    items={[
                        {
                            title: 'Documents',
                            href: documentRoutes.library.url(),
                        },
                        {
                            title: 'Requests',
                            href: documentRoutes.requests.url({
                                query: { tab: 'recipient' },
                            }),
                        },
                        { title: recipient_request.action_label },
                    ]}
                />

                <DetailsHeader
                    title={recipient_request.document.title ?? 'Document'}
                    description={`${recipient_request.action_label} for ${recipient_request.recipient_name}`}
                    backHref={documentRoutes.requests.url({
                        query: { tab: 'recipient' },
                    })}
                    backLabel="Back to requests"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.create &&
                            recipient_request.status === 'awaiting_action' &&
                            recipient_request.email_delivery?.can_resend ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            ResendDocumentRecipientRequestEmailController.url(
                                                {
                                                    recipientRequest:
                                                        recipient_request.id,
                                                },
                                            ),
                                        )
                                    }
                                >
                                    {recipient_request.email_delivery.status ===
                                    'failed'
                                        ? 'Retry email'
                                        : recipient_request.email_delivery
                                                .status === 'sent'
                                          ? 'Resend email'
                                          : 'Send email'}
                                </Button>
                            ) : null}
                            {can.cancel &&
                            recipient_request.status === 'awaiting_action' ? (
                                <>
                                    {recipient_request.is_public_token_recipient ? (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    RegenerateDocumentRecipientRequestTokenController.url(
                                                        {
                                                            recipientRequest:
                                                                recipient_request.id,
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            Regenerate link
                                        </Button>
                                    ) : null}
                                    <Button
                                        variant="destructive"
                                        onClick={() =>
                                            router.post(
                                                CancelDocumentRecipientRequestController.url(
                                                    {
                                                        recipientRequest:
                                                            recipient_request.id,
                                                    },
                                                ),
                                            )
                                        }
                                    >
                                        Cancel request
                                    </Button>
                                </>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Request details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Status
                                </span>
                                <Badge variant="secondary">
                                    {recipient_request.status_label}
                                </Badge>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Recipient role
                                </span>
                                <span>
                                    {recipient_request.recipient_role_label}
                                </span>
                            </div>
                            {recipient_request.company_signatory ? (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Company signatory
                                    </span>
                                    <span>
                                        {
                                            recipient_request.company_signatory
                                                .name
                                        }
                                    </span>
                                </div>
                            ) : null}
                            {recipient_request.respond_url ? (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Internal signing URL
                                    </span>
                                    <span className="max-w-[60%] truncate text-right">
                                        {recipient_request.respond_url}
                                    </span>
                                </div>
                            ) : null}
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Expires
                                </span>
                                <span>
                                    {recipient_request.status === 'expired'
                                        ? `Expired ${formatDisplayDate(recipient_request.expires_at)}`
                                        : formatDisplayDate(
                                              recipient_request.expires_at,
                                          )}
                                </span>
                            </div>
                            {recipient_request.reminder_summary ? (
                                <>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            Automatic reminders
                                        </span>
                                        <span>
                                            {recipient_request.reminder_summary
                                                .enabled &&
                                            recipient_request.reminder_summary
                                                .days_before_expiry.length > 0
                                                ? `${recipient_request.reminder_summary.days_before_expiry.join(', ')} days before expiry`
                                                : 'None'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            Next reminder
                                        </span>
                                        <span>
                                            {recipient_request.reminder_summary
                                                .next_reminder_at
                                                ? formatDisplayDate(
                                                      recipient_request
                                                          .reminder_summary
                                                          .next_reminder_at,
                                                  )
                                                : 'None'}
                                        </span>
                                    </div>
                                </>
                            ) : null}
                            {recipient_request.email_delivery ? (
                                <>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            Email
                                        </span>
                                        <Badge variant="secondary">
                                            {
                                                recipient_request.email_delivery
                                                    .status_label
                                            }
                                        </Badge>
                                    </div>
                                    {recipient_request.email_delivery
                                        .last_sent_at ? (
                                        <div className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                Last sent
                                            </span>
                                            <span>
                                                {formatDisplayDate(
                                                    recipient_request
                                                        .email_delivery
                                                        .last_sent_at,
                                                )}
                                            </span>
                                        </div>
                                    ) : null}
                                </>
                            ) : null}
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Action
                                </span>
                                <span>{recipient_request.action_label}</span>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Source version
                                </span>
                                <span>
                                    v{recipient_request.source_version.version}{' '}
                                    (
                                    {
                                        recipient_request.source_version
                                            .checksum_abbrev
                                    }
                                    )
                                </span>
                            </div>
                            {recipient_request.result_version ? (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Result version
                                    </span>
                                    <span>
                                        v
                                        {
                                            recipient_request.result_version
                                                .version
                                        }{' '}
                                        (
                                        {
                                            recipient_request.result_version
                                                .checksum_abbrev
                                        }
                                        )
                                    </span>
                                </div>
                            ) : null}
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Requested
                                </span>
                                <span>
                                    {formatDisplayDate(
                                        recipient_request.requested_at,
                                    )}
                                </span>
                            </div>
                            {recipient_request.completed_at ? (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Completed
                                    </span>
                                    <span>
                                        {formatDisplayDate(
                                            recipient_request.completed_at,
                                        )}
                                    </span>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Evidence timeline</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {recipient_request.timeline.map((item) => (
                                <div
                                    key={`${item.event}-${item.occurred_at}`}
                                    className="flex items-start justify-between gap-4 border-b pb-3 text-sm last:border-b-0 last:pb-0"
                                >
                                    <div>
                                        <p className="font-medium capitalize">
                                            {item.event.replaceAll('_', ' ')}
                                        </p>
                                        {item.actor_name ? (
                                            <p className="text-muted-foreground">
                                                {item.actor_name}
                                            </p>
                                        ) : null}
                                    </div>
                                    <span className="text-muted-foreground">
                                        {formatDisplayDate(item.occurred_at)}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                {can_view_audit ? (
                    <RecentActivityCard
                        items={recent_activity}
                        description="Recent activity for this recipient request."
                    />
                ) : null}
            </Main>
        </>
    );
}
