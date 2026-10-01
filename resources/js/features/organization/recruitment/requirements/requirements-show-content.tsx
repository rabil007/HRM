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
import type {
    RequirementLine,
    RequirementShowProps,
} from '@/types/recruitment';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { RequirementFormSheet } from './components/requirement-form-sheet';
import {
    RequirementPriorityBadge,
    RequirementStatusBadge,
} from './components/requirement-status-badge';
import { RequirementWhatsNextPanel } from './components/requirement-whats-next-panel';
import { RequirementAttachmentsCard } from './components/show/requirement-attachments-card';
import { RequirementDetailsCard } from './components/show/requirement-details-card';
import { RequirementOverviewCard } from './components/show/requirement-overview-card';
import { RequirementPositionLinesCard } from './components/show/requirement-position-lines-card';
import { CancelRequirementDialog } from './components/workflow/cancel-requirement-dialog';
import { ChangeHeadcountDialog } from './components/workflow/change-headcount-dialog';
import { ExtendDeadlineDialog } from './components/workflow/extend-deadline-dialog';
import { ReopenRequirementDialog } from './components/workflow/reopen-requirement-dialog';
import { RepeatRequirementDialog } from './components/workflow/repeat-requirement-dialog';
import { ReturnRequirementDialog } from './components/workflow/return-requirement-dialog';
import type { RequirementWhatsNextAction } from './lib/requirement-whats-next';

export function RequirementsShowContent({
    requirement,
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
    const [isReopenOpen, setIsReopenOpen] = useState(false);
    const [isRepeatOpen, setIsRepeatOpen] = useState(false);
    const [isReturnOpen, setIsReturnOpen] = useState(false);
    const [targetLineForHeadcount, setTargetLineForHeadcount] =
        useState<RequirementLine | null>(null);

    const [isWorkflowProcessing, setIsWorkflowProcessing] = useState(false);

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
        runWorkflow(
            RequirementSubmitController.url(requirement.id),
            'Requirement submitted for approval.',
            'Failed to submit requirement for approval.',
        );
    };

    const handleResubmit = () => {
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

    const handleWhatsNext = (
        action: Exclude<RequirementWhatsNextAction, null>,
    ) => {
        switch (action) {
            case 'submit':
                handleSubmit();
                break;
            case 'approve':
                handleApprove();
                break;
            case 'resubmit':
                handleResubmit();
                break;
            case 'resume':
                handleResume();
                break;
            case 'fill':
                handleFill();
                break;
            case 'extend':
                setIsExtendOpen(true);
                break;
            case 'repeat':
                setIsRepeatOpen(true);
                break;
            case 'edit':
                setIsEditOpen(true);
                break;
            case 'reopen':
                setIsReopenOpen(true);
                break;
            default:
                break;
        }
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
                description={`${requirement.client_name}${requirement.project_title ? ` • ${requirement.project_title}` : ''}${requirement.required_by_date_formatted ? ` • Required by ${requirement.required_by_date_formatted}` : ''}`}
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
                <div className="order-1 space-y-6 lg:order-2 lg:col-span-1">
                    <RequirementWhatsNextPanel
                        requirement={requirement}
                        onAction={handleWhatsNext}
                        processing={isWorkflowProcessing}
                    />

                    <RequirementOverviewCard
                        requirement={requirement}
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
                            setTargetLineForHeadcount(null);
                            setIsChangeHeadcountOpen(true);
                        }}
                        onFill={handleFill}
                        onCancel={() => setIsCancelOpen(true)}
                        onReopen={() => setIsReopenOpen(true)}
                        onRepeat={() => setIsRepeatOpen(true)}
                    />

                    {recent_activity && recent_activity.length > 0 && (
                        <RecentActivityCard
                            items={
                                recent_activity as unknown as RecentActivityItem[]
                            }
                            description="Audit log of requisition modifications and status changes."
                        />
                    )}
                </div>

                <div className="order-2 space-y-6 lg:order-1 lg:col-span-2">
                    <RequirementPositionLinesCard
                        requirement={requirement}
                        onChangeLineHeadcount={(line) => {
                            setTargetLineForHeadcount(line);
                            setIsChangeHeadcountOpen(true);
                        }}
                    />

                    <RequirementDetailsCard requirement={requirement} />

                    <RequirementAttachmentsCard
                        requirement={requirement}
                        canDownload={can.download_attachments}
                    />
                </div>
            </div>

            {/* Edit Form Sheet */}
            <RequirementFormSheet
                open={isEditOpen}
                onOpenChange={setIsEditOpen}
                initialRequirement={requirement}
                options={options}
            />

            {/* Workflow Dialogs */}
            <ExtendDeadlineDialog
                open={isExtendOpen}
                onOpenChange={setIsExtendOpen}
                requirement={requirement}
            />

            <ChangeHeadcountDialog
                open={isChangeHeadcountOpen}
                onOpenChange={setIsChangeHeadcountOpen}
                requirement={requirement}
                lines={
                    targetLineForHeadcount
                        ? [
                              {
                                  id: targetLineForHeadcount.id,
                                  position_title:
                                      targetLineForHeadcount.position_title,
                                  required_headcount:
                                      targetLineForHeadcount.required_headcount,
                              },
                          ]
                        : undefined
                }
            />

            <CancelRequirementDialog
                open={isCancelOpen}
                onOpenChange={setIsCancelOpen}
                requirement={requirement}
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
