import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import RequirementApproveController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementApproveController';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import RequirementFillController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementFillController';
import RequirementHoldController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHoldController';
import RequirementResubmitController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementResubmitController';
import RequirementResumeController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementResumeController';
import RequirementSubmitController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementSubmitController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { toast } from '@/lib/toast';
import type { RequirementShowProps } from '@/types/recruitment';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { RequirementFormSheet } from './components/requirement-form-sheet';
import {
    RequirementPriorityBadge,
    RequirementStatusBadge,
} from './components/requirement-status-badge';
import { DeadlineExtensionHistoryCard } from './components/show/deadline-extension-history-card';
import { HeadcountRevisionHistoryCard } from './components/show/headcount-revision-history-card';
import { RequirementAttachmentsCard } from './components/show/requirement-attachments-card';
import { RequirementDetailsWorkflowCard } from './components/show/requirement-details-workflow-card';
import { RequirementOverviewCard } from './components/show/requirement-overview-card';
import { RequirementPositionLinesCard } from './components/show/requirement-position-lines-card';
import { SubmissionReadinessBlockedDialog } from './components/submission-readiness-blocked-dialog';
import { CancelRequirementDialog } from './components/workflow/cancel-requirement-dialog';
import { ChangeHeadcountDialog } from './components/workflow/change-headcount-dialog';
import { DeadlineExtensionRequestCard } from './components/workflow/deadline-extension-request-card';
import { ExtendDeadlineDialog } from './components/workflow/extend-deadline-dialog';
import { HeadcountRevisionRequestCard } from './components/workflow/headcount-revision-request-card';
import { ReopenRequirementDialog } from './components/workflow/reopen-requirement-dialog';
import { RepeatRequirementDialog } from './components/workflow/repeat-requirement-dialog';
import { ReturnRequirementDialog } from './components/workflow/return-requirement-dialog';
import { TransferOwnershipDialog } from './components/workflow/transfer-ownership-dialog';
import { isRequirementHoldActionVisible } from './lib/requirement-hold-feature';
import {
    incompleteSubmissionMessages,
    isSubmissionReadinessComplete,
} from './lib/requirement-submission-readiness';

export function RequirementsShowContent({
    requirement,
    workflow_timeline,
    options,
    can,
    recent_activity,
}: RequirementShowProps) {
    const [isEditOpen, setIsEditOpen] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        const params = new URLSearchParams(window.location.search);

        return (
            params.get('edit') === '1' &&
            Boolean(requirement.can_edit && can.update)
        );
    });
    const [isExtendOpen, setIsExtendOpen] = useState(false);
    const [isChangeHeadcountOpen, setIsChangeHeadcountOpen] = useState(false);
    const [isCancelOpen, setIsCancelOpen] = useState(false);
    const [isTransferOwnershipOpen, setIsTransferOwnershipOpen] =
        useState(false);
    const [isReopenOpen, setIsReopenOpen] = useState(false);
    const [isRepeatOpen, setIsRepeatOpen] = useState(false);
    const [isReturnOpen, setIsReturnOpen] = useState(false);
    const [readinessBlockedOpen, setReadinessBlockedOpen] = useState(false);

    const [isWorkflowProcessing, setIsWorkflowProcessing] = useState(false);

    const readinessBlockedMessages = incompleteSubmissionMessages(
        requirement.submission_readiness ?? {
            ready: false,
            remaining_count: 0,
            items: [],
        },
    );

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const params = new URLSearchParams(window.location.search);

        if (params.get('edit') === '1') {
            const newUrl = new URL(window.location.href);

            newUrl.searchParams.delete('edit');
            window.history.replaceState({}, '', newUrl.toString());
        }
    }, []);

    const runWorkflow = (
        url: string,
        successMessage: string,
        errorMessage: string,
    ) => {
        setIsWorkflowProcessing(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(successMessage),
                onError: () => toast.error(errorMessage),
                onFinish: () => setIsWorkflowProcessing(false),
            },
        );
    };

    const handleHold = () => {
        if (!isRequirementHoldActionVisible(requirement.can_hold)) {
            return;
        }

        runWorkflow(
            RequirementHoldController.url(requirement.id),
            'Requirement put on hold.',
            'Failed to put requirement on hold.',
        );
    };

    const handleResume = () => {
        runWorkflow(
            RequirementResumeController.url(requirement.id),
            'Requirement resumed.',
            'Failed to resume requirement.',
        );
    };

    const handleFill = () => {
        runWorkflow(
            RequirementFillController.url(requirement.id),
            'Requirement marked as completed.',
            'Failed to mark requirement as filled.',
        );
    };

    const handleSubmit = () => {
        if (!isSubmissionReadinessComplete(requirement.submission_readiness)) {
            setReadinessBlockedOpen(true);

            return;
        }

        runWorkflow(
            RequirementSubmitController.url(requirement.id),
            'Requirement submitted for approval.',
            'Failed to submit requirement for approval.',
        );
    };

    const handleResubmit = () => {
        if (!isSubmissionReadinessComplete(requirement.submission_readiness)) {
            setReadinessBlockedOpen(true);

            return;
        }

        runWorkflow(
            RequirementResubmitController.url(requirement.id),
            'Requirement resubmitted for approval.',
            'Failed to resubmit requirement.',
        );
    };

    const handleApprove = () => {
        runWorkflow(
            RequirementApproveController.url(requirement.id),
            'Requirement approved.',
            'Failed to approve requirement.',
        );
    };

    return (
        <Main>
            <RecruitmentBreadcrumbs
                items={[
                    {
                        title: 'Requirements',
                        href: RequirementController.index.url(),
                    },
                    { title: requirement.requirement_number },
                ]}
            />

            <DetailsHeader
                kicker="Recruitment / Requirements"
                title={requirement.requirement_number}
                description={`${requirement.client_name}${requirement.project_title ? ` • ${requirement.project_title}` : ''}${requirement.required_by_date_formatted ? ` • Target Date ${requirement.required_by_date_formatted}` : ''}`}
                backHref={RequirementController.index.url()}
                backLabel="Requirements"
                badges={
                    <>
                        <RequirementStatusBadge
                            status={requirement.status}
                            label={requirement.status_label}
                        />
                        <RequirementPriorityBadge
                            priority={requirement.priority}
                        />
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div
                    data-requirement-sidebar
                    className="order-1 min-w-0 space-y-6 overflow-x-visible [scrollbar-width:thin] lg:sticky lg:top-4 lg:order-2 lg:col-span-1 lg:max-h-[calc(100vh-2rem)] lg:self-start lg:overflow-x-visible lg:overflow-y-auto lg:pr-1"
                >
                    <DeadlineExtensionRequestCard requirement={requirement} />
                    <HeadcountRevisionRequestCard requirement={requirement} />

                    <RequirementOverviewCard
                        requirement={requirement}
                        timeline={workflow_timeline}
                        processing={isWorkflowProcessing}
                        onEdit={() => setIsEditOpen(true)}
                        onSubmit={handleSubmit}
                        onApprove={handleApprove}
                        onReturn={() => setIsReturnOpen(true)}
                        onResubmit={handleResubmit}
                        onHold={handleHold}
                        onResume={handleResume}
                        onExtend={() => setIsExtendOpen(true)}
                        onChangeHeadcount={() => {
                            setIsChangeHeadcountOpen(true);
                        }}
                        onFill={handleFill}
                        onCancel={() => setIsCancelOpen(true)}
                        onTransferOwnership={() =>
                            setIsTransferOwnershipOpen(true)
                        }
                        onReopen={() => setIsReopenOpen(true)}
                        onRepeat={() => setIsRepeatOpen(true)}
                    />

                    <DeadlineExtensionHistoryCard
                        extensions={requirement.deadline_extensions ?? []}
                    />
                    <HeadcountRevisionHistoryCard
                        revisions={requirement.headcount_revisions ?? []}
                    />
                </div>

                <div
                    data-requirement-main-column
                    className="order-2 space-y-6 lg:order-1 lg:col-span-2"
                >
                    <RequirementPositionLinesCard
                        requirement={requirement}
                        onChangeLineHeadcount={() => {
                            setIsChangeHeadcountOpen(true);
                        }}
                    />

                    <RequirementDetailsWorkflowCard requirement={requirement} />

                    <RequirementAttachmentsCard
                        requirement={requirement}
                        canDownload={can.download_attachments}
                    />

                    {recent_activity && recent_activity.length > 0 && (
                        <RecentActivityCard
                            items={
                                recent_activity as unknown as RecentActivityItem[]
                            }
                            description="Audit log of requisition modifications and non-status changes."
                        />
                    )}
                </div>
            </div>

            <RequirementFormSheet
                open={isEditOpen}
                onOpenChange={setIsEditOpen}
                initialRequirement={requirement}
                canSubmit={can.submit}
                options={options}
            />

            <SubmissionReadinessBlockedDialog
                open={readinessBlockedOpen}
                onOpenChange={setReadinessBlockedOpen}
                messages={readinessBlockedMessages}
                onContinueEditing={() => {
                    if (requirement.can_edit && can.update) {
                        setIsEditOpen(true);
                    }
                }}
            />

            <ExtendDeadlineDialog
                open={isExtendOpen}
                onOpenChange={setIsExtendOpen}
                requirement={requirement}
            />

            <ChangeHeadcountDialog
                open={isChangeHeadcountOpen}
                onOpenChange={setIsChangeHeadcountOpen}
                requirement={requirement}
            />

            <CancelRequirementDialog
                open={isCancelOpen}
                onOpenChange={setIsCancelOpen}
                requirement={requirement}
            />

            <TransferOwnershipDialog
                open={isTransferOwnershipOpen}
                onOpenChange={setIsTransferOwnershipOpen}
                requirement={requirement}
                requesters={
                    options.requesters ?? options.notification_users ?? []
                }
                recruiters={options.recruiters}
            />

            <ReopenRequirementDialog
                open={isReopenOpen}
                onOpenChange={setIsReopenOpen}
                requirement={requirement}
            />

            <RepeatRequirementDialog
                open={isRepeatOpen}
                onOpenChange={setIsRepeatOpen}
                requirement={requirement}
                recruiters={options.recruiters}
            />

            <ReturnRequirementDialog
                open={isReturnOpen}
                onOpenChange={setIsReturnOpen}
                requirement={requirement}
            />
        </Main>
    );
}
