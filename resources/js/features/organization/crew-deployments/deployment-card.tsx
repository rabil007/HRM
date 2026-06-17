import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import {
    AlertTriangle,
    Anchor,
    BadgeCheck,
    Building2,
    Clock,
    GripVertical,
    MoreVertical,
    Ship,
} from 'lucide-react';
import type { CSSProperties, ReactElement } from 'react';
import { router } from '@inertiajs/react';
import { show as showDeployment } from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmployeeProfileLink } from '@/features/organization/crew-deployments/employee-profile-link';
import type { DeploymentItem } from '@/features/organization/crew-deployments/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

// ─── colour maps ──────────────────────────────────────────────────────────────

export const STATUS_ACCENT: Record<string, string> = {
    unknown: 'border-l-red-500',
    arrived: 'border-l-sky-500',
    join_standby: 'border-l-amber-500',
    on_vessel: 'border-l-emerald-500',
    disembarked: 'border-l-rose-500',
    leave_standby: 'border-l-orange-500',
    travel: 'border-l-violet-500',
    in_home: 'border-l-teal-500',
};

const DAYS_COLORS: Record<string, string> = {
    on_vessel: 'from-emerald-500/15 to-emerald-500/5 text-emerald-700 dark:text-emerald-300',
    join_standby: 'from-amber-500/15 to-amber-500/5 text-amber-700 dark:text-amber-300',
    leave_standby: 'from-orange-500/15 to-orange-500/5 text-orange-700 dark:text-orange-300',
    in_home: 'from-teal-500/15 to-teal-500/5 text-teal-700 dark:text-teal-300',
};

const FIELD_LABELS: Record<string, string> = {
    arrived_date: 'Arrived',
    join_standby_from: 'Standby from',
    join_standby_to: 'Standby to',
    joined_date: 'Joined',
    disembarked_date: 'Disembarked',
    leave_standby_from: 'Leave from',
    leave_standby_to: 'Leave to',
    travelled_date: 'Travelled',
};

// ─── helpers ──────────────────────────────────────────────────────────────────

export function getInitials(name: string | null): string {
    if (!name) {
        return '?';
    }

    return name
        .split(' ')
        .slice(0, 2)
        .map((n) => n[0])
        .join('')
        .toUpperCase();
}

function getKeyDates(
    d: DeploymentItem,
): { label: string; value: string; isOverdue: boolean; isDueSoon: boolean }[] {
    const field = (key: string, label: string) => ({
        label,
        value: formatDisplayDate(d[key as keyof DeploymentItem] as string | null),
        isOverdue: d.overdue_date_fields.includes(key),
        isDueSoon: d.due_soon_date_fields.includes(key),
    });

    switch (d.status) {
        case 'arrived':
            return [field('arrived_date', 'Arrived')];
        case 'join_standby':
            return [field('join_standby_from', 'From'), field('join_standby_to', 'To')];
        case 'on_vessel':
            return [
                field('joined_date', 'Joined'),
                ...(d.disembarked_date ? [field('disembarked_date', 'Expected off')] : []),
            ];
        case 'disembarked':
            return [
                field('disembarked_date', 'Disembarked'),
                ...(d.joined_date ? [field('joined_date', 'Was joined')] : []),
            ];
        case 'leave_standby':
            return [field('leave_standby_from', 'From'), field('leave_standby_to', 'To')];
        case 'travel':
            return [field('travelled_date', 'Travelled')];
        default:
            return [];
    }
}

// ─── component ────────────────────────────────────────────────────────────────

type Props = {
    deployment: DeploymentItem;
    can: { manage: boolean };
    onEdit: (deployment: DeploymentItem) => void;
    onDelete: (deployment: DeploymentItem) => void;
    backQuery?: Record<string, string>;
    isOverlay?: boolean;
};

export function DeploymentCard({
    deployment: d,
    can,
    onEdit,
    onDelete,
    backQuery,
    isOverlay = false,
}: Props): ReactElement {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: d.id,
        data: { deployment: d },
        disabled: isOverlay || !can.manage,
    });

    const style: CSSProperties = transform ? { transform: CSS.Translate.toString(transform) } : {};

    const handleClick = (): void => {
        if (isDragging) {
            return;
        }

        router.visit(
            showDeployment.url(
                { deployment: d.id },
                backQuery && Object.keys(backQuery).length > 0 ? { query: backQuery } : undefined,
            ),
        );
    };

    // Days counter
    let daysLabel: string | null = null;
    let daysValue: number | null = null;

    if (d.status === 'on_vessel' && d.vessel_days) {
        daysLabel = 'days on board';
        daysValue = d.vessel_days;
    } else if (d.status === 'join_standby' && d.join_standby_days) {
        daysLabel = 'days in standby';
        daysValue = d.join_standby_days;
    } else if (d.status === 'leave_standby' && d.leave_standby_days) {
        daysLabel = 'days in standby';
        daysValue = d.leave_standby_days;
    } else if (d.status === 'in_home' && d.in_home_days) {
        daysLabel = 'days at home';
        daysValue = d.in_home_days;
    }

    const keyDates = getKeyDates(d);
    const hasOverdue = d.overdue_date_fields.length > 0;
    const hasDueSoon = !hasOverdue && d.due_soon_date_fields.length > 0;
    const isUnknown = d.status === 'unknown';
    const daysBg = DAYS_COLORS[d.status] ?? 'from-muted/60 to-muted/30 text-foreground';

    // Determine the top alert bar content
    const topAlert: { icon: ReactElement; text: string; classes: string } | null = (() => {
        if (isUnknown || hasOverdue) {
            const text = isUnknown
                ? (d.status_hint ?? 'Status unclear — update required')
                : d.overdue_date_fields.map((f) => FIELD_LABELS[f] ?? f).join(', ') + ' overdue';

            return {
                icon: <AlertTriangle className="h-3 w-3 shrink-0" />,
                text,
                classes:
                    'bg-red-500/10 border-b border-red-500/20 text-red-700 dark:text-red-300',
            };
        }

        if (hasDueSoon) {
            return {
                icon: <Clock className="h-3 w-3 shrink-0" />,
                text:
                    d.due_soon_date_fields.map((f) => FIELD_LABELS[f] ?? f).join(', ') +
                    ' due soon',
                classes:
                    'bg-amber-500/10 border-b border-amber-500/20 text-amber-700 dark:text-amber-300',
            };
        }

        return null;
    })();

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={cn('transition-opacity', isDragging && 'opacity-40')}
        >
            <Card
                className={cn(
                    'group relative cursor-pointer overflow-hidden border-l-4 bg-card shadow-sm',
                    'transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md',
                    STATUS_ACCENT[d.status] ?? 'border-l-border',
                    isOverlay && 'rotate-1 shadow-2xl',
                )}
                onClick={handleClick}
            >
                {/* ── Top alert bar ─────────────────────────────────── */}
                {topAlert ? (
                    <div
                        className={cn(
                            'flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-semibold',
                            topAlert.classes,
                        )}
                    >
                        {topAlert.icon}
                        <span className="truncate">{topAlert.text}</span>
                    </div>
                ) : null}

                <CardContent className="p-0">
                    {/* ── Header ───────────────────────────────────────── */}
                    <div className="flex items-start gap-2.5 px-3.5 pt-3">
                        {/* Drag handle */}
                        {can.manage && !isOverlay ? (
                            <div
                                {...attributes}
                                {...listeners}
                                className="mt-1 cursor-grab touch-none opacity-0 transition-opacity group-hover:opacity-30 active:cursor-grabbing"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <GripVertical className="h-4 w-4 shrink-0 text-muted-foreground" />
                            </div>
                        ) : null}

                        {/* Avatar */}
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-bold uppercase tracking-wide text-muted-foreground ring-2 ring-background">
                            {getInitials(d.employee_name)}
                        </div>

                        {/* Name + meta */}
                        <div className="min-w-0 flex-1">
                            {d.employee_name ? (
                                <EmployeeProfileLink
                                    employeeId={d.employee_id}
                                    className="block truncate text-[13px] font-bold leading-tight"
                                    stopRowNavigation
                                >
                                    {d.employee_name}
                                </EmployeeProfileLink>
                            ) : (
                                <div className="truncate text-[13px] font-bold leading-tight text-muted-foreground">
                                    Unknown
                                </div>
                            )}
                            <div className="mt-0.5 flex items-center gap-1.5 text-[10px] text-muted-foreground/70">
                                <span className="font-mono tracking-wide">
                                    {d.employee_no ?? '—'}
                                </span>
                                {d.nationality ? (
                                    <>
                                        <span className="opacity-40">·</span>
                                        <span>{d.nationality}</span>
                                    </>
                                ) : null}
                            </div>
                        </div>

                        {/* Actions */}
                        {can.manage && !isOverlay ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    className="rounded-md p-1 opacity-0 transition-opacity hover:bg-muted group-hover:opacity-100 focus:opacity-100"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <MoreVertical className="h-3.5 w-3.5 text-muted-foreground" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onEdit(d);
                                        }}
                                    >
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onDelete(d);
                                        }}
                                        className="text-destructive focus:text-destructive"
                                    >
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : null}
                    </div>

                    {/* ── Divider ──────────────────────────────────────── */}
                    <div className="mx-3.5 mt-3 border-t border-border/40" />

                    {/* ── Vessel + rank + client ────────────────────────── */}
                    <div className="space-y-2 px-3.5 pt-2.5">
                        <div className="flex items-center gap-1.5">
                            <Ship className="h-3 w-3 shrink-0 text-muted-foreground/50" />
                            <span className="truncate text-xs font-medium text-foreground/80">
                                {d.vessel_name ?? (
                                    <span className="text-muted-foreground">No vessel</span>
                                )}
                            </span>
                        </div>

                        <div className="flex flex-wrap items-center gap-1.5">
                            {d.rank_name ? (
                                <Badge
                                    variant="outline"
                                    className="flex items-center gap-1 px-1.5 py-0 text-[10px] font-semibold"
                                >
                                    <Anchor className="h-2.5 w-2.5" />
                                    {d.rank_name}
                                </Badge>
                            ) : null}
                            {d.client_name ? (
                                <Badge
                                    variant="outline"
                                    className="flex items-center gap-1 px-1.5 py-0 text-[10px] font-medium text-muted-foreground"
                                >
                                    <Building2 className="h-2.5 w-2.5" />
                                    {d.client_name}
                                </Badge>
                            ) : null}
                            {d.company_visa_type_name ? (
                                <Badge
                                    variant="outline"
                                    className="flex items-center gap-1 px-1.5 py-0 text-[10px] font-medium text-muted-foreground"
                                >
                                    <BadgeCheck className="h-2.5 w-2.5" />
                                    {d.company_visa_type_name}
                                </Badge>
                            ) : null}
                        </div>
                    </div>

                    {/* ── Key dates ────────────────────────────────────── */}
                    {keyDates.length > 0 ? (
                        <div className="mx-3.5 mt-3 grid grid-cols-2 gap-x-3 gap-y-2 rounded-lg border border-border/30 bg-muted/30 px-3 py-2.5">
                            {keyDates.map(({ label, value, isOverdue, isDueSoon }) => (
                                <div key={label} className="min-w-0">
                                    <div className="text-[9px] font-bold uppercase tracking-widest text-muted-foreground/60">
                                        {label}
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-0.5 text-[11px] font-semibold tabular-nums',
                                            isOverdue && 'text-red-600 dark:text-red-400',
                                            !isOverdue &&
                                                isDueSoon &&
                                                'text-amber-600 dark:text-amber-400',
                                            !isOverdue && !isDueSoon && 'text-foreground',
                                        )}
                                    >
                                        {value}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : null}

                    {/* ── Days counter ─────────────────────────────────── */}
                    {daysValue && daysLabel ? (
                        <div
                            className={cn(
                                'mx-3.5 mt-3 flex items-baseline gap-2 rounded-lg bg-gradient-to-br px-3 py-2',
                                daysBg,
                            )}
                        >
                            <span className="text-2xl font-black tabular-nums leading-none">
                                {daysValue}
                            </span>
                            <span className="text-[9px] font-bold uppercase tracking-widest opacity-70">
                                {daysLabel}
                            </span>
                        </div>
                    ) : null}

                    {/* ── Remarks ──────────────────────────────────────── */}
                    {d.remarks ? (
                        <div className="mx-3.5 mt-2.5 truncate text-[10px] italic text-muted-foreground/60">
                            {d.remarks}
                        </div>
                    ) : null}

                    <div className="h-3" />
                </CardContent>
            </Card>
        </div>
    );
}
