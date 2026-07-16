import { router } from '@inertiajs/react';
import { Download, Eye, FileUp, Loader2 } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { Textarea } from '@/components/ui/textarea';
import {
    employeeDocumentViewUrl,
    signedPdfDownloadUrl,
    signedPdfViewUrl,
} from '@/features/organization/documents/bulk/bulk-document-urls';
import { SignatureStatusBadge } from '@/features/organization/documents/bulk/signature-status-badge';
import type { BulkSignatureRequest } from '@/features/organization/documents/bulk/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { cn } from '@/lib/utils';

function signatureRequestViewUrl(
    request: BulkSignatureRequest,
    canReview: boolean,
    canDownload: boolean,
): string | null {
    if (request.signed_pdf_path && canReview) {
        return signedPdfViewUrl(request.id);
    }

    if (request.unsigned_document_id && canDownload) {
        return employeeDocumentViewUrl(request.unsigned_document_id);
    }

    return null;
}

function reviewDescription(request: BulkSignatureRequest): string {
    if (request.status === 'submitted') {
        return 'Review the signed PDF below, then approve or reject with a reason.';
    }

    if (request.status === 'awaiting_signature') {
        return 'Waiting for the employee to sign electronically, or upload a scanned signed PDF.';
    }

    if (request.status === 'rejected') {
        return 'This request was rejected. Upload a new scanned signed PDF to resubmit.';
    }

    if (request.status === 'approved') {
        return 'This signature has been approved and applied to the employee file.';
    }

    return 'Signature request details.';
}

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

    const resetDialogState = () => {
        setRejectReason('');
        setIsSubmitting(false);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            resetDialogState();
        }

        onOpenChange(nextOpen);
    };

    if (!request) {
        return null;
    }

    const signedViewUrl = request.signed_pdf_path
        ? signedPdfViewUrl(request.id)
        : null;
    const signedDownloadUrl = request.signed_pdf_path
        ? signedPdfDownloadUrl(request.id)
        : null;

    const canUploadManual =
        canReview &&
        (request.status === 'awaiting_signature' ||
            request.status === 'rejected');

    const canApproveOrReject = canReview && request.status === 'submitted';

    const approve = () => {
        setIsSubmitting(true);
        router.post(
            `/organization/documents/bulk/signatures/${request.id}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    handleOpenChange(false);
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
                    handleOpenChange(false);
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
                    handleOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-w-lg gap-0 overflow-hidden p-0">
                <DialogHeader className="space-y-4 border-b border-border/80 px-6 py-5">
                    <div className="flex items-start gap-3">
                        <EmployeeAvatar
                            name={request.employee.name}
                            image={request.employee.image}
                            size="md"
                        />
                        <div className="min-w-0 flex-1 space-y-1">
                            <DialogTitle className="text-left text-lg">
                                {request.employee.name}
                            </DialogTitle>
                            <DialogDescription className="text-left">
                                {reviewDescription(request)}
                            </DialogDescription>
                            <div className="flex flex-wrap items-center gap-2 pt-1">
                                <SignatureStatusBadge status={request.status} />
                                {request.employee.employee_no ? (
                                    <span className="text-xs text-muted-foreground">
                                        {request.employee.employee_no}
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-4 px-6 py-5">
                    {(request.signed_name || request.signed_at) && (
                        <div className="rounded-xl border border-border/80 bg-muted/20 px-4 py-3 text-sm">
                            {request.signed_name ? (
                                <p>
                                    <span className="text-muted-foreground">
                                        Signed as{' '}
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {request.signed_name}
                                    </span>
                                </p>
                            ) : null}
                            {request.signed_at ? (
                                <p className="text-muted-foreground">
                                    Submitted{' '}
                                    {formatDisplayDateTime12h(
                                        request.signed_at,
                                    )}
                                </p>
                            ) : null}
                        </div>
                    )}

                    {request.rejection_reason ? (
                        <div className="rounded-xl border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm">
                            <p className="font-medium text-destructive">
                                Rejection reason
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                {request.rejection_reason}
                            </p>
                        </div>
                    ) : null}

                    {signedViewUrl ? (
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-border/80 bg-card px-4 py-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    Signed PDF
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Open the employee&apos;s submission before
                                    approving.
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={signedViewUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <Eye className="mr-2 h-4 w-4" />
                                        View
                                    </a>
                                </Button>
                                {signedDownloadUrl ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={signedDownloadUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <Download className="mr-2 h-4 w-4" />
                                            Download
                                        </a>
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-border/80 bg-muted/10 px-4 py-6 text-center text-sm text-muted-foreground">
                            No signed PDF has been submitted yet.
                        </div>
                    )}

                    {canUploadManual ? (
                        <div className="space-y-2 rounded-xl border border-dashed border-border/80 bg-muted/10 p-4">
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <FileUp className="h-4 w-4 text-muted-foreground" />
                                Upload scanned signed PDF
                            </div>
                            <Input
                                ref={fileInputRef}
                                type="file"
                                accept="application/pdf"
                                disabled={isSubmitting}
                                onChange={(event) => {
                                    const file = event.target.files?.[0];

                                    if (file) {
                                        uploadManual(file);
                                    }
                                }}
                            />
                        </div>
                    ) : null}

                    {canApproveOrReject ? (
                        <div className="space-y-2">
                            <Label
                                htmlFor="reject_reason"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Rejection reason
                            </Label>
                            <Textarea
                                id="reject_reason"
                                value={rejectReason}
                                onChange={(event) =>
                                    setRejectReason(event.target.value)
                                }
                                disabled={isSubmitting}
                                className="min-h-20 rounded-xl"
                                placeholder="Required only when rejecting"
                            />
                        </div>
                    ) : null}
                </div>

                <DialogFooter className="gap-2 border-t border-border/80 px-6 py-4 sm:justify-between">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={isSubmitting}
                    >
                        Close
                    </Button>
                    {canApproveOrReject ? (
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
                                disabled={
                                    isSubmitting || !request.signed_pdf_path
                                }
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

export function canRegenerateSignatureAlignment(
    request: BulkSignatureRequest,
): boolean {
    return (
        Boolean(request.signed_pdf_path) &&
        Boolean(request.signature_image_path) &&
        (request.status === 'submitted' || request.status === 'approved')
    );
}

export function BulkSignaturesTable({
    requests,
    canReview,
    canDownload,
    header,
    selectable = false,
    isSelected,
    isAllSelected,
    isPartiallySelected,
    onToggle,
    onToggleAll,
}: {
    requests: BulkSignatureRequest[];
    canReview: boolean;
    canDownload: boolean;
    header?: React.ReactNode;
    selectable?: boolean;
    isSelected?: (id: number) => boolean;
    isAllSelected?: boolean;
    isPartiallySelected?: boolean;
    onToggle?: (id: number) => void;
    onToggleAll?: () => void;
}) {
    const [reviewRequest, setReviewRequest] =
        useState<BulkSignatureRequest | null>(null);

    const selectableIds = requests.map((request) => request.id);

    const columnCount = selectable ? 6 : 5;

    return (
        <>
            <OrganizationDataTable minWidth="min-w-[880px]" header={header}>
                <TableHeader>
                    <DataTableHeaderRow>
                        {selectable ? (
                            <DataTableHead className="w-10">
                                <Checkbox
                                    checked={
                                        isAllSelected
                                            ? true
                                            : isPartiallySelected
                                              ? 'indeterminate'
                                              : false
                                    }
                                    onCheckedChange={() => onToggleAll?.()}
                                    disabled={selectableIds.length === 0}
                                    aria-label="Select all signature requests"
                                />
                            </DataTableHead>
                        ) : null}
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Status</DataTableHead>
                        <DataTableHead>Submitted</DataTableHead>
                        <DataTableHead>Reviewed</DataTableHead>
                        <DataTableHead className="text-right">
                            Actions
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {requests.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={columnCount} className="p-0">
                                <EmptyState
                                    title="No signature requests yet."
                                    description="Send bulk emails with signing links to create requests."
                                />
                            </TableCell>
                        </TableRow>
                    ) : (
                        requests.map((request) => {
                            const viewUrl = signatureRequestViewUrl(
                                request,
                                canReview,
                                canDownload,
                            );

                            return (
                                <TableRow
                                    key={request.id}
                                    className={dataTableBodyRowClass(false)}
                                >
                                    {selectable ? (
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <Checkbox
                                                checked={Boolean(
                                                    isSelected?.(request.id),
                                                )}
                                                onCheckedChange={() =>
                                                    onToggle?.(request.id)
                                                }
                                                aria-label={`Select ${request.employee.name}`}
                                            />
                                        </TableCell>
                                    ) : null}
                                    <TableCell
                                        className={cn(
                                            dataTableCellPrimaryClass(),
                                            'min-w-[200px]',
                                        )}
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <EmployeeProfileLink
                                                employeeId={request.employee.id}
                                                stopRowNavigation
                                                className="shrink-0"
                                            >
                                                <EmployeeAvatar
                                                    name={request.employee.name}
                                                    image={
                                                        request.employee.image
                                                    }
                                                    size="sm"
                                                />
                                            </EmployeeProfileLink>
                                            <div className="min-w-0">
                                                <EmployeeProfileLink
                                                    employeeId={
                                                        request.employee.id
                                                    }
                                                    className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                                                    stopRowNavigation
                                                >
                                                    {request.employee.name}
                                                </EmployeeProfileLink>
                                                <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                                                    {request.employee
                                                        .employee_no ?? '—'}
                                                </p>
                                                {(request.employee.department ||
                                                    request.employee
                                                        .position) && (
                                                    <p className="truncate text-[11px] text-muted-foreground/60">
                                                        {[
                                                            request.employee
                                                                .department,
                                                            request.employee
                                                                .position,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <SignatureStatusBadge
                                            status={request.status}
                                        />
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {request.signed_at
                                            ? formatDisplayDateTime12h(
                                                  request.signed_at,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {request.reviewed_at
                                            ? `${request.reviewed_by ?? 'HR'} · ${formatDisplayDateTime12h(request.reviewed_at)}`
                                            : '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="flex justify-end gap-2">
                                            {viewUrl ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    asChild
                                                >
                                                    <a
                                                        href={viewUrl}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        onClick={(event) =>
                                                            event.stopPropagation()
                                                        }
                                                    >
                                                        <Eye className="mr-2 h-4 w-4" />
                                                        View
                                                    </a>
                                                </Button>
                                            ) : null}
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setReviewRequest(request)
                                                }
                                            >
                                                Review
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            );
                        })
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
