import { router } from '@inertiajs/react';
import { Flame } from 'lucide-react';
import { useState } from 'react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import RequirementFillController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementFillController';
import RequirementHoldController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHoldController';
import RequirementOpenController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementOpenController';
import RequirementResumeController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementResumeController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type {
    RequirementLine,
    RequirementShowProps,
} from '@/types/recruitment';
import { RequirementFormSheet } from './components/requirement-form-sheet';
import { RequirementAttachmentsCard } from './components/show/requirement-attachments-card';
import { RequirementDetailsCard } from './components/show/requirement-details-card';
import { RequirementOverviewCard } from './components/show/requirement-overview-card';
import { RequirementPositionLinesCard } from './components/show/requirement-position-lines-card';
import { CancelRequirementDialog } from './components/workflow/cancel-requirement-dialog';
import { ChangeHeadcountDialog } from './components/workflow/change-headcount-dialog';
import { ExtendDeadlineDialog } from './components/workflow/extend-deadline-dialog';
import { ReopenRequirementDialog } from './components/workflow/reopen-requirement-dialog';
import { RepeatRequirementDialog } from './components/workflow/repeat-requirement-dialog';

export function RequirementsShowContent({
    requirement,
    options,
    can,
    recent_activity,
}: RequirementShowProps) {
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [isExtendOpen, setIsExtendOpen] = useState(false);
    const [isChangeHeadcountOpen, setIsChangeHeadcountOpen] = useState(false);
    const [isCancelOpen, setIsCancelOpen] = useState(false);
    const [isReopenOpen, setIsReopenOpen] = useState(false);
    const [isRepeatOpen, setIsRepeatOpen] = useState(false);
    const [targetLineForHeadcount, setTargetLineForHeadcount] =
        useState<RequirementLine | null>(null);

    const handleOpen = () => {
        router.post(
            RequirementOpenController.url(requirement.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Requirement opened.'),
                onError: () => toast.error('Failed to open requirement.'),
            },
        );
    };

    const handleHold = () => {
        router.post(
            RequirementHoldController.url(requirement.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Requirement put on hold.'),
                onError: () =>
                    toast.error('Failed to put requirement on hold.'),
            },
        );
    };

    const handleResume = () => {
        router.post(
            RequirementResumeController.url(requirement.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Requirement resumed.'),
                onError: () => toast.error('Failed to resume requirement.'),
            },
        );
    };

    const handleFill = () => {
        router.post(
            RequirementFillController.url(requirement.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success('Requirement marked as completed.'),
                onError: () =>
                    toast.error('Failed to mark requirement as filled.'),
            },
        );
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Recruitment Requisition"
                title={requirement.requirement_number}
                description={`${requirement.client_name}${requirement.project_title ? ` • ${requirement.project_title}` : ''}`}
                backHref={RequirementController.index.url()}
                backLabel="Requirements"
                badges={
                    <>
                        <Badge
                            variant="outline"
                            className={cn(
                                'px-2.5 py-0.5 text-xs font-semibold',
                                requirement.status === 'open' &&
                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                                requirement.status === 'draft' &&
                                    'border-zinc-500/30 bg-zinc-500/10 text-zinc-400',
                                requirement.status === 'on_hold' &&
                                    'border-amber-500/30 bg-amber-500/10 text-amber-500',
                                requirement.status === 'completed' &&
                                    'border-sky-500/30 bg-sky-500/10 text-sky-500',
                                requirement.status === 'cancelled' &&
                                    'border-rose-500/30 bg-rose-500/10 text-rose-500',
                            )}
                        >
                            {requirement.status_label}
                        </Badge>
                        {requirement.priority === 'urgent' && (
                            <Badge
                                variant="outline"
                                className="gap-1 border-rose-500/30 bg-rose-500/10 px-2.5 py-0.5 font-bold text-rose-500"
                            >
                                <Flame className="h-3 w-3 fill-rose-500" />
                                Urgent
                            </Badge>
                        )}
                    </>
                }
            />

            <div className="space-y-6">
                {/* Overview Card with Progress & Actions */}
                <RequirementOverviewCard
                    requirement={requirement}
                    onEdit={() => setIsEditOpen(true)}
                    onOpen={handleOpen}
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

                {/* Position Lines Table */}
                <RequirementPositionLinesCard
                    requirement={requirement}
                    onChangeLineHeadcount={(line) => {
                        setTargetLineForHeadcount(line);
                        setIsChangeHeadcountOpen(true);
                    }}
                />

                {/* Requirement Specifications & Details */}
                <RequirementDetailsCard requirement={requirement} />

                {/* Attachments Card */}
                <RequirementAttachmentsCard
                    requirement={requirement}
                    canDownload={can.download_attachments}
                />

                {/* Recent Activity / Audit Log */}
                {recent_activity && recent_activity.length > 0 && (
                    <RecentActivityCard
                        items={
                            recent_activity as unknown as RecentActivityItem[]
                        }
                        description="Audit log of requisition modifications and status changes."
                    />
                )}
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
        </Main>
    );
}
