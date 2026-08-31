import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import CancelDocumentWorkflowRequestController from '@/actions/App/Http/Controllers/Organization/Documents/CancelDocumentWorkflowRequestController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { DocumentPreviewPanel } from '@/features/organization/documents/shared/document-preview-panel';
import type {
    DocumentWorkflowPermissions,
    WorkflowRequestDetail,
} from '@/features/organization/documents/workflow/types';
import { WorkflowDecisionButtons } from '@/features/organization/documents/workflow/workflow-decision-dialog';
import { WorkflowStatusBadge } from '@/features/organization/documents/workflow/workflow-status-badge';
import { WorkflowTimeline } from '@/features/organization/documents/workflow/workflow-timeline';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import documentRoutes from '@/routes/organization/documents';

type Props = {
    request: WorkflowRequestDetail;
    can: DocumentWorkflowPermissions;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

export default function DocumentWorkflowRequestShow({
    request,
    can,
    recent_activity,
    can_view_audit,
}: Props): ReactElement {
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');

    const documentShowHref =
        request.document.id && request.document.employee_id
            ? documentRoutes.employee.files.show.url({
                  employee: request.document.employee_id,
                  document: request.document.id,
              })
            : null;

    function submitCancel() {
        router.post(
            CancelDocumentWorkflowRequestController.url({
                workflowRequest: request.id,
            }),
            { reason: cancelReason },
            {
                preserveScroll: true,
                onSuccess: () => setCancelOpen(false),
            },
        );
    }

    return (
        <>
            <Head title={`Request #${request.id}`} />

            <Main>
                <DetailsHeader
                    kicker="Review & Approval"
                    title={request.document.title ?? 'Document request'}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <span>{request.employee.name}</span>
                            <span className="text-muted-foreground">·</span>
                            <span>{request.employee.employee_no}</span>
                            <WorkflowStatusBadge
                                status={request.status}
                                label={request.status_label}
                            />
                        </span>
                    }
                    backHref={documentRoutes.requests.url()}
                    backLabel="Back to requests"
                    actions={
                        request.status === 'pending' && can.cancel ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCancelOpen(true)}
                            >
                                Cancel request
                            </Button>
                        ) : null
                    }
                />

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Workflow
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <WorkflowTimeline stages={request.stages} />
                            </CardContent>
                        </Card>

                        {request.viewer_task ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Your action
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <WorkflowDecisionButtons
                                        request={request}
                                        canReview={can.review}
                                        canApprove={can.approve}
                                    />
                                </CardContent>
                            </Card>
                        ) : null}

                        {request.document.file_url ? (
                            <DocumentPreviewPanel
                                document={{
                                    title: request.document.title,
                                    file_url: request.document.file_url,
                                    can_preview: true,
                                }}
                            />
                        ) : null}
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Requested by
                                    </div>
                                    <div>{request.requested_by.name}</div>
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Requested
                                    </div>
                                    <div>
                                        {request.requested_at
                                            ? formatDisplayDateTime12h(
                                                  request.requested_at,
                                              )
                                            : '—'}
                                    </div>
                                </div>
                                {request.provenance ? (
                                    <>
                                        <div>
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Template
                                            </div>
                                            <div>
                                                {
                                                    request.provenance
                                                        .template_name
                                                }{' '}
                                                (v
                                                {
                                                    request.provenance
                                                        .template_version
                                                }
                                                )
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Bound version
                                            </div>
                                            <div>
                                                v
                                                {request.provenance
                                                    .bound_version ?? '—'}
                                            </div>
                                        </div>
                                    </>
                                ) : null}
                                {documentShowHref ? (
                                    <Link
                                        href={documentShowHref}
                                        className="inline-flex text-sm font-medium text-primary hover:underline"
                                    >
                                        Open document
                                    </Link>
                                ) : null}
                            </CardContent>
                        </Card>

                        {can_view_audit ? (
                            <RecentActivityCard
                                items={recent_activity}
                                description="Latest activity for this document."
                            />
                        ) : null}
                    </div>
                </div>
            </Main>

            <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel workflow request</DialogTitle>
                        <DialogDescription>
                            Pending stages and tasks will be cancelled.
                            Completed history is retained.
                        </DialogDescription>
                    </DialogHeader>
                    <Textarea
                        value={cancelReason}
                        onChange={(event) =>
                            setCancelReason(event.target.value)
                        }
                        placeholder="Optional reason"
                        rows={3}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setCancelOpen(false)}
                        >
                            Close
                        </Button>
                        <Button type="button" onClick={submitCancel}>
                            Cancel request
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
