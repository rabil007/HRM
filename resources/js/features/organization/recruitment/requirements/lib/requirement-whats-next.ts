import type {
    RequirementDetail,
    RequirementIndexRow,
} from '@/types/recruitment';

export type RequirementWhatsNextAction =
    | 'open'
    | 'resume'
    | 'fill'
    | 'extend'
    | 'repeat'
    | 'edit'
    | 'reopen'
    | null;

export type RequirementWhatsNext = {
    action: RequirementWhatsNextAction;
    title: string;
    description: string;
    ctaLabel: string | null;
    tone: 'primary' | 'warning' | 'success' | 'neutral';
};

type RequirementCapabilities = Pick<
    RequirementIndexRow,
    | 'status'
    | 'status_label'
    | 'next_action'
    | 'can_open'
    | 'can_resume'
    | 'can_fill'
    | 'can_extend'
    | 'can_repeat'
    | 'can_edit'
    | 'can_reopen'
    | 'deadline_health'
    | 'days_label'
    | 'requirement_number'
>;

const ACTION_COPY: Record<
    Exclude<RequirementWhatsNextAction, null>,
    {
        title: string;
        description: string;
        ctaLabel: string;
        tone: RequirementWhatsNext['tone'];
    }
> = {
    open: {
        title: 'Open this requirement',
        description:
            'Move this draft into active recruitment so recruiters can staff the requested roles.',
        ctaLabel: 'Open requirement',
        tone: 'success',
    },
    resume: {
        title: 'Resume recruitment',
        description:
            'This requirement is on hold. Resume it when hiring can continue.',
        ctaLabel: 'Resume requirement',
        tone: 'success',
    },
    fill: {
        title: 'Mark as filled',
        description:
            'When staffing is complete, mark the requirement filled and move it to history.',
        ctaLabel: 'Mark as filled',
        tone: 'primary',
    },
    extend: {
        title: 'Extend the deadline',
        description:
            'This requirement is overdue. Extend the required-by date with a reason, or continue staffing.',
        ctaLabel: 'Extend deadline',
        tone: 'warning',
    },
    repeat: {
        title: 'Repeat this requirement',
        description:
            'Create a new draft with the same client and positions. Dates and ownership should be reviewed before opening.',
        ctaLabel: 'Repeat requirement',
        tone: 'primary',
    },
    edit: {
        title: 'Review draft details',
        description:
            'Update client, ownership, or notes before opening this draft for recruitment.',
        ctaLabel: 'Edit requirement',
        tone: 'neutral',
    },
    reopen: {
        title: 'Reopen this requirement',
        description:
            'Bring this closed requirement back into an active state when hiring needs to continue.',
        ctaLabel: 'Reopen requirement',
        tone: 'primary',
    },
};

function authorizedAction(
    requirement: RequirementCapabilities,
    preferred: RequirementWhatsNextAction,
): RequirementWhatsNextAction {
    switch (preferred) {
        case 'open':
            return requirement.can_open ? 'open' : null;
        case 'resume':
            return requirement.can_resume ? 'resume' : null;
        case 'fill':
            return requirement.can_fill ? 'fill' : null;
        case 'extend':
            return requirement.can_extend ? 'extend' : null;
        case 'repeat':
            return requirement.can_repeat ? 'repeat' : null;
        case 'edit':
            return requirement.can_edit ? 'edit' : null;
        case 'reopen':
            return requirement.can_reopen ? 'reopen' : null;
        default:
            return null;
    }
}

function fallbackAction(
    requirement: RequirementCapabilities,
): RequirementWhatsNextAction {
    if (requirement.can_open) {
        return 'open';
    }

    if (requirement.can_resume) {
        return 'resume';
    }

    if (requirement.can_reopen) {
        return 'reopen';
    }

    if (requirement.deadline_health === 'overdue' && requirement.can_extend) {
        return 'extend';
    }

    if (requirement.can_fill) {
        return 'fill';
    }

    if (requirement.can_repeat) {
        return 'repeat';
    }

    if (requirement.can_edit) {
        return 'edit';
    }

    return null;
}

export function resolveRequirementWhatsNext(
    requirement: RequirementCapabilities | RequirementDetail,
): RequirementWhatsNext {
    const preferred = authorizedAction(
        requirement,
        requirement.next_action as RequirementWhatsNextAction,
    );
    const action = preferred ?? fallbackAction(requirement);

    if (!action) {
        return {
            action: null,
            title: 'No immediate action',
            description: `${requirement.requirement_number} is ${requirement.status_label.toLowerCase()}. Available actions depend on your permissions and the current status.`,
            ctaLabel: null,
            tone: 'neutral',
        };
    }

    const copy = ACTION_COPY[action];
    const overdueSuffix =
        action === 'extend' && requirement.days_label
            ? ` Current status: ${requirement.days_label}.`
            : '';

    return {
        action,
        title: copy.title,
        description: `${copy.description}${overdueSuffix}`,
        ctaLabel: copy.ctaLabel,
        tone: copy.tone,
    };
}
