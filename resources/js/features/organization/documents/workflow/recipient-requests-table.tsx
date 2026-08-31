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
                        <TableHead>Employee</TableHead>
                        <TableHead>Document</TableHead>
                        <TableHead>Waiting for</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Requested</TableHead>
                        <TableHead className="text-right">Action</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.map((request) => {
                        return (
                            <TableRow key={request.id}>
                                <TableCell className="font-medium">
                                    <div>{request.employee.name}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {request.employee.employee_no ?? ''}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div className="font-medium">
                                        {request.document.title ?? 'Document'}
                                    </div>
                                    {request.signing_step_label ? (
                                        <div className="text-xs text-muted-foreground">
                                            {request.signing_step_label}
                                        </div>
                                    ) : null}
                                </TableCell>
                                <TableCell>
                                    {request.waiting_for || '—'}
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {request.human_status}
                                    </Badge>
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
