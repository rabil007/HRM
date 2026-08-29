import { Link, router } from '@inertiajs/react';
import ResendDocumentRecipientRequestEmailController from '@/actions/App/Http/Controllers/Organization/Documents/ResendDocumentRecipientRequestEmailController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { RecipientRequestListItem } from '@/features/organization/documents/workflow/types';
import { formatDisplayDate } from '@/lib/format-date';
import documentRoutes from '@/routes/organization/documents';

type Props = {
    requests: RecipientRequestListItem[];
    canRespond?: boolean;
    canCreate?: boolean;
};

function emailIndicatorLabel(
    delivery: RecipientRequestListItem['email_delivery'],
): string | null {
    if (!delivery) {
        return null;
    }

    switch (delivery.status) {
        case 'sent':
            return 'Email sent';
        case 'failed':
            return 'Email failed';
        case 'queued':
            return 'Email queued';
        case 'suppressed':
            return 'Email unavailable';
        default:
            return delivery.status_label;
    }
}

export function RecipientRequestsTable({
    requests,
    canRespond = false,
    canCreate = false,
}: Props) {
    if (requests.length === 0) {
        return (
            <p className="rounded-xl border bg-muted/20 px-4 py-8 text-center text-sm text-muted-foreground">
                No signing or acknowledgement requests yet.
            </p>
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Document</TableHead>
                        <TableHead>Subject employee</TableHead>
                        <TableHead>Recipient</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Action</TableHead>
                        <TableHead>Source</TableHead>
                        <TableHead>Result</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Requested</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.map((request) => {
                        const emailLabel = emailIndicatorLabel(
                            request.email_delivery,
                        );

                        return (
                            <TableRow key={request.id}>
                                <TableCell className="font-medium">
                                    {request.document.title ?? 'Document'}
                                </TableCell>
                                <TableCell>{request.employee.name}</TableCell>
                                <TableCell>{request.recipient_name}</TableCell>
                                <TableCell>
                                    {request.signing_step_sequence &&
                                    request.signing_step_label ? (
                                        <div className="space-y-0.5">
                                            <div className="font-medium">
                                                Step{' '}
                                                {request.signing_step_sequence}
                                                {request.signing_preset_name
                                                    ? ` / ${request.signing_preset_name}`
                                                    : ''}
                                            </div>
                                            <div>
                                                {request.signing_step_label}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {request.recipient_role_label}
                                            </div>
                                        </div>
                                    ) : (
                                        request.recipient_role_label
                                    )}
                                </TableCell>
                                <TableCell>{request.action_label}</TableCell>
                                <TableCell>
                                    {request.source_version.version
                                        ? `v${request.source_version.version}`
                                        : '—'}
                                </TableCell>
                                <TableCell>
                                    {request.result_version?.version
                                        ? `v${request.result_version.version}`
                                        : '—'}
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {request.status_label}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    {emailLabel ? (
                                        <span className="text-xs text-muted-foreground">
                                            {emailLabel}
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    {formatDisplayDate(request.requested_at)}
                                </TableCell>
                                <TableCell className="text-right">
                                    <div className="flex justify-end gap-2">
                                        {canRespond && request.respond_url ? (
                                            <Button
                                                variant="default"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={request.respond_url}
                                                >
                                                    Sign
                                                </Link>
                                            </Button>
                                        ) : null}
                                        {canCreate &&
                                        request.email_delivery?.can_resend ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        ResendDocumentRecipientRequestEmailController.url(
                                                            {
                                                                recipientRequest:
                                                                    request.id,
                                                            },
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {request.email_delivery
                                                    .status === 'failed'
                                                    ? 'Retry email'
                                                    : request.email_delivery
                                                            .status === 'sent'
                                                      ? 'Resend email'
                                                      : 'Send email'}
                                            </Button>
                                        ) : null}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={documentRoutes.recipientRequests.show.url(
                                                    {
                                                        recipientRequest:
                                                            request.id,
                                                    },
                                                )}
                                            >
                                                View
                                            </Link>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
