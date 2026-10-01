import type { RequirementIndexRow } from '@/types/recruitment';

export type VisibleRequirementActions = {
    canView: true;
    canEdit: boolean;
    canChangeHeadcount: boolean;
    canExtend: boolean;
    canHold: boolean;
    canResume: boolean;
    canFill: boolean;
    canReopen: boolean;
    canRepeat: boolean;
    canCancel: boolean;
};

export function visibleRequirementActions(
    row: Pick<
        RequirementIndexRow,
        | 'can_edit'
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
