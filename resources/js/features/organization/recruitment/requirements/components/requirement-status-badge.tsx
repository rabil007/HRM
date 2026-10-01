import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    CircleDashed,
    Clock,
    Flame,
    PauseCircle,
    PlayCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type {
    RequirementDeadlineHealth,
    RequirementPriority,
    RequirementStatus,
} from '@/types/recruitment';
import {
    REQUIREMENT_PRIORITY_LABELS,
    REQUIREMENT_STATUS_ICONS,
    isUrgentPriority,
    resolveRequirementDeadlineStyle,
    resolveRequirementPriorityStyle,
    resolveRequirementStatusLabel,
    resolveRequirementStatusStyle,
} from '../lib/requirement-status';

const STATUS_ICON: Record<
    (typeof REQUIREMENT_STATUS_ICONS)[RequirementStatus],
    LucideIcon
> = {
    draft: CircleDashed,
    open: PlayCircle,
    on_hold: PauseCircle,
    completed: CheckCircle2,
    cancelled: Ban,
};

export function RequirementStatusBadge({
    status,
    label,
    className,
    showIcon = true,
}: {
    status: RequirementStatus | string;
    label?: string | null;
    className?: string;
    showIcon?: boolean;
}) {
    const resolvedLabel = resolveRequirementStatusLabel(status, label);
    const Icon =
        STATUS_ICON[
            REQUIREMENT_STATUS_ICONS[status as RequirementStatus] ?? 'draft'
        ] ?? CircleDashed;

    return (
        <Badge
            variant="outline"
            className={cn(
                'gap-1 px-2 py-0.5 text-xs font-semibold',
                resolveRequirementStatusStyle(status),
                className,
            )}
        >
            {showIcon ? <Icon className="size-3" aria-hidden="true" /> : null}
            <span>{resolvedLabel}</span>
        </Badge>
    );
}

export function RequirementPriorityBadge({
    priority,
    label,
    className,
}: {
    priority: RequirementPriority | string;
    label?: string | null;
    className?: string;
}) {
    if (!isUrgentPriority(priority)) {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                'gap-1 px-2 py-0.5 text-xs font-semibold',
                resolveRequirementPriorityStyle(priority),
                className,
            )}
        >
            <Flame className="size-3 fill-current" aria-hidden="true" />
            <span>
                {label ??
                    REQUIREMENT_PRIORITY_LABELS[
                        priority as RequirementPriority
                    ] ??
                    'Urgent'}
            </span>
        </Badge>
    );
}

export function RequirementDeadlineBadge({
    health,
    label,
    className,
}: {
    health: RequirementDeadlineHealth | string | null | undefined;
    label?: string | null;
    className?: string;
}) {
    if (!health) {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                'w-fit gap-1 px-1.5 py-0 text-xs font-medium',
                resolveRequirementDeadlineStyle(health),
                className,
            )}
        >
            {health === 'overdue' ? (
                <AlertTriangle className="size-2.5" aria-hidden="true" />
            ) : null}
            {health === 'due_soon' ? (
                <Clock className="size-2.5" aria-hidden="true" />
            ) : null}
            <span>{label ?? health.replaceAll('_', ' ')}</span>
        </Badge>
    );
}
