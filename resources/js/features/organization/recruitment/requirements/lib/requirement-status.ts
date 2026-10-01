import type {
    RequirementDeadlineHealth,
    RequirementPriority,
    RequirementStatus,
} from '@/types/recruitment';

export const REQUIREMENT_STATUS_LABELS: Record<RequirementStatus, string> = {
    draft: 'Draft',
    open: 'Open',
    on_hold: 'On Hold',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const REQUIREMENT_STATUS_STYLES: Record<RequirementStatus, string> = {
    draft: 'border-zinc-500/30 bg-zinc-500/10 text-zinc-700 dark:text-zinc-300',
    open: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    on_hold:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    completed: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    cancelled:
        'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
};

export const REQUIREMENT_STATUS_ICONS: Record<
    RequirementStatus,
    'draft' | 'open' | 'on_hold' | 'completed' | 'cancelled'
> = {
    draft: 'draft',
    open: 'open',
    on_hold: 'on_hold',
    completed: 'completed',
    cancelled: 'cancelled',
};

export const REQUIREMENT_PRIORITY_LABELS: Record<RequirementPriority, string> =
    {
        normal: 'Normal',
        urgent: 'Urgent',
    };

export const REQUIREMENT_PRIORITY_STYLES: Record<RequirementPriority, string> =
    {
        normal: 'border-border/60 bg-muted/40 text-muted-foreground',
        urgent: 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
    };

export const REQUIREMENT_DEADLINE_STYLES: Record<
    RequirementDeadlineHealth,
    string
> = {
    on_track:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    due_soon:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    overdue:
        'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
};

export function resolveRequirementStatusLabel(
    status: RequirementStatus | string,
    label?: string | null,
): string {
    if (label) {
        return label;
    }

    return (
        REQUIREMENT_STATUS_LABELS[status as RequirementStatus] ??
        String(status).replaceAll('_', ' ')
    );
}

export function resolveRequirementStatusStyle(
    status: RequirementStatus | string,
): string {
    return (
        REQUIREMENT_STATUS_STYLES[status as RequirementStatus] ??
        REQUIREMENT_STATUS_STYLES.draft
    );
}

export function resolveRequirementPriorityStyle(
    priority: RequirementPriority | string,
): string {
    return (
        REQUIREMENT_PRIORITY_STYLES[priority as RequirementPriority] ??
        REQUIREMENT_PRIORITY_STYLES.normal
    );
}

export function resolveRequirementDeadlineStyle(
    health: RequirementDeadlineHealth | string,
): string {
    return (
        REQUIREMENT_DEADLINE_STYLES[health as RequirementDeadlineHealth] ??
        REQUIREMENT_DEADLINE_STYLES.on_track
    );
}

export function isUrgentPriority(
    priority: RequirementPriority | string | null | undefined,
): boolean {
    return priority === 'urgent';
}
