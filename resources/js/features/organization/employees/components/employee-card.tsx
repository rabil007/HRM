import { router } from '@inertiajs/react';
import { Cake, Eye, Mail, Phone, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Employee } from '../types';

const STATUS_CONFIG = {
    active: {
        dot: 'bg-emerald-400',
        badge: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-400',
        label: 'Active',
    },
    inactive: {
        dot: 'bg-zinc-400',
        badge: 'border-zinc-500/25 bg-zinc-500/10 text-zinc-400',
        label: 'Inactive',
    },
    on_leave: {
        dot: 'bg-amber-400 animate-pulse',
        badge: 'border-amber-500/25 bg-amber-500/10 text-amber-400',
        label: 'On Leave',
    },
    terminated: {
        dot: 'bg-rose-500',
        badge: 'border-rose-500/25 bg-rose-500/10 text-rose-400',
        label: 'Terminated',
    },
} as const;

const POSITION_COLORS = [
    'border-primary/20 bg-primary/10 text-primary',
    'border-violet-500/20 bg-violet-500/10 text-violet-400',
    'border-sky-500/20 bg-sky-500/10 text-sky-400',
    'border-emerald-500/20 bg-emerald-500/10 text-emerald-400',
    'border-amber-500/20 bg-amber-500/10 text-amber-400',
    'border-rose-500/20 bg-rose-500/10 text-rose-400',
];

function getAvatarGradient(name: string): string {
    const gradients = [
        'from-violet-600 to-indigo-600',
        'from-sky-600 to-cyan-600',
        'from-emerald-600 to-teal-600',
        'from-amber-600 to-orange-600',
        'from-rose-600 to-pink-600',
        'from-fuchsia-600 to-purple-600',
    ];
    const hash = name.split('').reduce((acc, c) => acc + c.charCodeAt(0), 0);

    return gradients[hash % gradients.length];
}

function formatBirthday(dateStr: string | null | undefined): string | null {
    if (!dateStr) {
        return null;
    }

    const date = new Date(dateStr);

    if (isNaN(date.getTime())) {
        return null;
    }

    return date.toLocaleDateString('en-US', { day: 'numeric', month: 'long' });
}

export function EmployeeCard({
    employee,
    showUrl,
    onDelete,
}: {
    employee: Employee;
    showUrl: string;
    onDelete?: (employee: Employee) => void;
}) {
    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const initials =
        employee.name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase() || 'E';

    const avatarGradient = getAvatarGradient(employee.name);
    const statusCfg = STATUS_CONFIG[employee.status] ?? STATUS_CONFIG.inactive;
    const positionColor = POSITION_COLORS[employee.name.length % POSITION_COLORS.length];
    const birthday = formatBirthday(employee.date_of_birth);

    return (
        <div
            className="group relative flex overflow-hidden rounded-2xl border border-white/8 bg-card/50 shadow-[0_4px_24px_rgba(0,0,0,0.3)] backdrop-blur-sm transition-all duration-200 hover:border-primary/30 hover:shadow-[0_8px_32px_rgba(0,0,0,0.4)] hover:-translate-y-0.5 cursor-pointer"
            onClick={() => router.visit(showUrl)}
        >
            {/* ── Left: Photo panel ── */}
            <div
                className={cn(
                    'w-28 shrink-0 self-stretch overflow-hidden bg-gradient-to-br',
                    avatarGradient,
                )}
            >
                {imageSrc ? (
                    <img
                        src={imageSrc}
                        alt={employee.name}
                        className="h-full w-full object-cover object-top"
                    />
                ) : (
                    <div className="flex h-full w-full select-none items-center justify-center text-3xl font-bold text-white/80">
                        {initials}
                    </div>
                )}
            </div>

            {/* ── Right: Details panel ── */}
            <div className="flex min-w-0 flex-1 flex-col justify-between p-3">
                {/* Name + ID + Status */}
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div
                            className="truncate text-sm font-bold uppercase tracking-wide text-foreground leading-tight"
                            title={employee.name}
                        >
                            {employee.name}
                        </div>
                        <span className="mt-1 inline-block rounded-md bg-white/8 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-muted-foreground/70">
                            {employee.employee_no}
                        </span>
                    </div>
                    <div
                        className={cn(
                            'flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                            statusCfg.badge,
                        )}
                    >
                        <span className={cn('h-1.5 w-1.5 rounded-full', statusCfg.dot)} />
                        {statusCfg.label}
                    </div>
                </div>

                {/* Contact rows */}
                <div className="mt-2 flex flex-col gap-1.5">
                    <div className="flex items-center gap-2 text-xs text-muted-foreground/70 min-w-0">
                        <Mail className="h-3 w-3 shrink-0 text-primary/60" />
                        <span className="truncate">{employee.work_email ?? '—'}</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground/70 min-w-0">
                        <Phone className="h-3 w-3 shrink-0 text-primary/60" />
                        <span className="truncate">{employee.phone ?? '—'}</span>
                    </div>
                    {birthday ? (
                        <div className="flex items-center gap-2 text-xs text-muted-foreground/70 min-w-0">
                            <Cake className="h-3 w-3 shrink-0 text-primary/60" />
                            <span>{birthday}</span>
                        </div>
                    ) : null}
                </div>

                {/* Position chip + actions */}
                <div className="mt-2.5 flex items-center justify-between gap-2">
                    {employee.position?.title ? (
                        <span
                            className={cn(
                                'inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold truncate',
                                positionColor,
                            )}
                        >
                            {employee.position.title}
                        </span>
                    ) : (
                        <span />
                    )}

                    {/* Action buttons */}
                    <div className="flex shrink-0 items-center gap-0.5">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7 rounded-lg hover:bg-primary/10 hover:text-primary text-muted-foreground/50 transition-colors"
                            onClick={(e) => {
                                e.stopPropagation();
                                router.visit(showUrl);
                            }}
                            title="View"
                        >
                            <Eye className="h-3.5 w-3.5" />
                        </Button>
                        {onDelete ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7 rounded-lg hover:bg-destructive/10 hover:text-destructive text-muted-foreground/50 transition-colors"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onDelete(employee);
                                }}
                                title="Delete"
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    );
}
