import { router } from '@inertiajs/react';
import { Eye, Loader2 } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { SignatureStatusBadge } from '@/features/organization/documents/bulk/signature-status-badge';
import type { BulkSignatureRequest } from '@/features/organization/documents/bulk/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDateTime12h } from '@/lib/format-date';

export function SignatureReviewDialog({
    request,
    open,
    onOpenChange,
    canReview,
}: {
    request: BulkSignatureRequest | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canReview: boolean;
}) {
    const [rejectReason, setRejectReason] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    if (!request) {
        return null;
    }

    const unsignedUrl = request.unsigned_document_id
        ? `/organization/documents/files/${request.unsigned_document_id}/download`
        : null;
    const signedUrl = request.signed_pdf_path
        ? `/organization/documents/bulk/signatures/${request.id}/download`
        : null;

    const approve = () => {
        setIsSubmitting(true);
        router.post(
            `/organization/documents/bulk/signatures/${request.id}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    onOpenChange(false);
                },
            },
        );
    };

    const reject = () => {
        if (!rejectReason.trim()) {
            return;
        }

        setIsSubmitting(true);
        router.post(
            `/organization/documents/bulk/signatures/${request.id}/reject`,
            { reason: rejectReason.trim() },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    setRejectReason('');
                    onOpenChange(false);
                },
            },
        );
    };

    const uploadManual = (file: File) => {
        const formData = new FormData();
        formData.append('file', file);
        setIsSubmitting(true);

        router.post(
            `/organization/documents/bulk/signatures/${request.id}/upload`,
            formData,
            {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        Review signature — {request.employee.name}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label>Unsigned document</Label>
                        {unsignedUrl ? (
                            <Button variant="outline" size="sm" asChild>
                                <a href={unsignedUrl} target="_blank" rel="noreferrer">
                                    <Eye className="mr-2 h-4 w-4" />
                                    View unsigned PDF
                                </a>
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">Not available</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label>Signed submission</Label>
                        {signedUrl ? (
                            <Button variant="outline" size="sm" asChild>
                                <a href={signedUrl} target="_blank" rel="noreferrer">
                                    <Eye className="mr-2 h-4 w-4" />
                                    View signed PDF
                                </a>
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No signed PDF uploaded yet.
                            </p>
                        )}
                    </div>
                </div>

                {request.signed_name ? (
                    <p className="text-sm text-muted-foreground">
                        Signed as <strong>{request.signed_name}</strong>
                        {request.signed_at
                            ? ` on ${formatDisplayDateTime12h(request.signed_at)}`
                            : ''}
                    </p>
                ) : null}

                {canReview &&
                (request.status === 'awaiting_signature' ||
                    request.status === 'rejected') ? (
                    <div className="space-y-2 rounded-lg border border-dashed p-4">
                        <Label>Upload scanned signed PDF</Label>
                        <Input
                            ref={fileInputRef}
                            type="file"
                            accept="application/pdf"
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    uploadManual(file);
                                }
                            }}
                        />
                    </div>
                ) : null}

                {canReview && request.status === 'submitted' ? (
                    <div className="space-y-2">
                        <Label htmlFor="reject_reason">Rejection reason</Label>
                        <Input
                            id="reject_reason"
                            value={rejectReason}
                            onChange={(event) =>
                                setRejectReason(event.target.value)
                            }
                            placeholder="Required only when rejecting"
                        />
                    </div>
                ) : null}

                <DialogFooter className="gap-2 sm:justify-between">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    {canReview && request.status === 'submitted' ? (
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="text-destructive"
                                disabled={isSubmitting || !rejectReason.trim()}
                                onClick={reject}
                            >
                                Reject
                            </Button>
                            <Button
                                type="button"
                                disabled={isSubmitting || !request.signed_pdf_path}
                                onClick={approve}
                            >
                                {isSubmitting ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : null}
                                Approve
                            </Button>
                        </div>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function BulkSignaturesTable({
    requests,
    canReview,
    header,
}: {
    requests: BulkSignatureRequest[];
    canReview: boolean;
    header?: React.ReactNode;
}) {
    const [reviewRequest, setReviewRequest] =
        useState<BulkSignatureRequest | null>(null);

    return (
        <>
            <OrganizationDataTable
                minWidth="min-w-[880px]"
                header={header}
            >
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Status</DataTableHead>
                        <DataTableHead>Submitted</DataTableHead>
                        <DataTableHead>Reviewed</DataTableHead>
                        <DataTableHead className="text-right">Actions</DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {requests.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={5} className="p-0">
                                <EmptyState
                                    title="No signature requests yet."
                                    description="Send bulk emails with signing links to create requests."
                                />
                            </TableCell>
                        </TableRow>
                    ) : (
                        requests.map((request) => (
                            <TableRow
                                key={request.id}
                                className={dataTableBodyRowClass(false)}
                            >
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    <div className="flex items-center gap-3">
                                        <EmployeeAvatar
                                            name={request.employee.name}
                                            image={request.employee.image}
                                            size="sm"
                                        />
                                        <div>
                                            <EmployeeProfileLink
                                                employeeId={request.employee.id}
                                                stopRowNavigation
                                            >
                                                {request.employee.name}
                                            </EmployeeProfileLink>
                                            <div className="text-xs text-muted-foreground/70">
                                                {request.employee.employee_no ?? '—'}
                                            </div>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <SignatureStatusBadge status={request.status} />
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {request.signed_at
                                        ? formatDisplayDateTime12h(request.signed_at)
                                        : '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {request.reviewed_at
                                        ? `${request.reviewed_by ?? 'HR'} · ${formatDisplayDateTime12h(request.reviewed_at)}`
                                        : '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setReviewRequest(request)}
                                        >
                                            Review
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>

            <SignatureReviewDialog
                request={reviewRequest}
                open={reviewRequest !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setReviewRequest(null);
                    }
                }}
                canReview={canReview}
            />
        </>
    );
}
