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
};

export function RecipientRequestsTable({ requests }: Props) {
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
                        <TableHead>Employee</TableHead>
                        <TableHead>Action</TableHead>
                        <TableHead>Requested by</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Requested</TableHead>
                        <TableHead>Expires</TableHead>
                        <TableHead>Completed</TableHead>
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
                            <TableCell>{request.action_label}</TableCell>
                            <TableCell>
                                {request.requested_by.name ?? '—'}
                            </TableCell>
                            <TableCell>
                                <Badge variant="secondary">
                                    {request.status_label}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                {formatDisplayDate(request.requested_at)}
                            </TableCell>
                            <TableCell>
                                {formatDisplayDate(request.expires_at)}
                            </TableCell>
                            <TableCell>
                                {formatDisplayDate(request.completed_at)}
                            </TableCell>
                            <TableCell className="text-right">
                                <Button variant="ghost" size="sm" asChild>
                                    <Link
                                        href={documentRoutes.recipientRequests.show.url(
                                            {
                                                recipientRequest: request.id,
                                            },
                                        )}
                                    >
                                        View
                                    </Link>
                                </Button>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
