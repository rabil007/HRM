import { Link } from '@inertiajs/react';
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
};

export function RecipientRequestsTable({
    requests,
    canRespond = false,
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
                        <TableHead>Requested</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.map((request) => (
                        <TableRow key={request.id}>
                            <TableCell className="font-medium">
                                {request.document.title ?? 'Document'}
                            </TableCell>
                            <TableCell>{request.employee.name}</TableCell>
                            <TableCell>{request.recipient_name}</TableCell>
                            <TableCell>
                                {request.recipient_role_label}
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
                                            <Link href={request.respond_url}>
                                                Sign
                                            </Link>
                                        </Button>
                                    ) : null}
                                    <Button variant="ghost" size="sm" asChild>
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
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
