import type { RequirementIndexRow } from '@/types/recruitment';

export type VisibleRequirementActions = {
    canView: true;
    canEdit: boolean;
    canSubmit: boolean;
    canApprove: boolean;
    canReturn: boolean;
    canResubmit: boolean;
    canChangeHeadcount: boolean;
    canExtend: boolean;
    canHold: boolean;
    canResume: boolean;
    canFill: boolean;
    canReopen: boolean;
    canRepeat: boolean;
    canCancel: boolean;
};

export type RequirementPrimaryWorkflowAction =
    | 'approve'
    | 'submit'
    | 'resubmit'
    | 'resume'
    | 'fill'
    | null;

type RequirementPrimaryActionCapabilities = Pick<
    RequirementIndexRow,
    'can_approve' | 'can_submit' | 'can_resubmit' | 'can_resume' | 'can_fill'
>;

export function visibleRequirementActions(
    row: Pick<
        RequirementIndexRow,
        | 'can_edit'
        | 'can_submit'
        | 'can_approve'
        | 'can_return'
        | 'can_resubmit'
        | 'can_change_headcount'
        | 'can_extend'
        | 'can_hold'
        | 'can_resume'
        | 'can_fill'
        | 'can_reopen'
        | 'can_repeat'
        | 'can_cancel'
    >,
): VisibleRequirementActions {
    return {
        canView: true,
        canEdit: row.can_edit,
        canSubmit: row.can_submit,
        canApprove: row.can_approve,
        canReturn: row.can_return,
        canResubmit: row.can_resubmit,
        canChangeHeadcount: row.can_change_headcount,
        canExtend: row.can_extend,
        canHold: row.can_hold,
        canResume: row.can_resume,
        canFill: row.can_fill,
        canReopen: row.can_reopen,
        canRepeat: row.can_repeat,
        canCancel: row.can_cancel,
    };
}

/**
 * Resolve the single primary workflow action for the requirement detail sidebar.
 * Priority matches the Status & actions card — never more than one primary CTA.
 */
export function resolveRequirementPrimaryWorkflowAction(
    requirement: RequirementPrimaryActionCapabilities,
): RequirementPrimaryWorkflowAction {
    if (requirement.can_approve) {
        return 'approve';
    }

    if (requirement.can_submit) {
        return 'submit';
    }

    if (requirement.can_resubmit) {
        return 'resubmit';
    }

    if (requirement.can_resume) {
        return 'resume';
    }

    if (requirement.can_fill) {
        return 'fill';
    }

    return null;
}

export function requirementPrimaryWorkflowActionLabel(
    action: Exclude<RequirementPrimaryWorkflowAction, null>,
): string {
    switch (action) {
        case 'approve':
            return 'Approve requirement';
        case 'submit':
            return 'Submit for approval';
        case 'resubmit':
            return 'Resubmit for approval';
        case 'resume':
            return 'Resume requirement';
        case 'fill':
            return 'Mark as filled';
    }
}
