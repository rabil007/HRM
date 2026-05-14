import { router } from '@inertiajs/react';
import { Briefcase, Building2, Eye, Mail, Phone, Trash2 } from 'lucide-react';
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

export function EmployeeCard({
    employee,
    onDelete,
}: {
    employee: Employee;
    onDelete?: (employee: Employee) => void;
}) {
    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const initials = employee.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase() || 'E';
    const avatarGradient = getAvatarGradient(employee.name);
    const statusCfg = STATUS_CONFIG[employee.status] ?? STATUS_CONFIG.inactive;

    return (
        <div
            className="group relative flex flex-col overflow-hidden rounded-2xl border border-white/8 bg-card/50 shadow-[0_4px_24px_rgba(0,0,0,0.3)] backdrop-blur-sm transition-all duration-200 hover:border-primary/30 hover:shadow-[0_8px_32px_rgba(0,0,0,0.4)] hover:-translate-y-0.5 cursor-pointer"
            onClick={() => router.visit(`/organization/employees/${employee.id}`)}
        >
            {/* ── Top: Avatar + Name + Status ── */}
            <div className="flex items-center gap-3 px-4 pt-4 pb-3">
                {/* Avatar */}
                <div className={cn('h-11 w-11 shrink-0 overflow-hidden rounded-xl border border-white/10 bg-gradient-to-br shadow-md', avatarGradient)}>
                    {imageSrc ? (
                        <img src={imageSrc} alt={employee.name} className="h-full w-full object-cover" />
                    ) : (
                        <div className="flex h-full w-full select-none items-center justify-center text-sm font-bold text-white/90">
                            {initials}
                        </div>
                    )}
                </div>

                {/* Name + ID */}
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-foreground leading-tight" title={employee.name}>
                        {employee.name}
                    </div>
                    <div className="mt-0.5 font-mono text-[10px] font-medium tracking-widest text-muted-foreground/55 uppercase">
                        {employee.employee_no}
                    </div>
                </div>

                {/* Status */}
                <div className={cn('flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold', statusCfg.badge)}>
                    <span className={cn('h-1.5 w-1.5 rounded-full', statusCfg.dot)} />
                    {statusCfg.label}
                </div>
            </div>

            {/* ── Role chips ── */}
            <div className="flex flex-wrap gap-1.5 px-4 pb-3">
                {employee.position?.title ? (
                    <span className="inline-flex items-center gap-1 rounded-md border border-primary/20 bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">
                        <Briefcase className="h-2.5 w-2.5" />
                        {employee.position.title}
                    </span>
                ) : null}
                {employee.department?.name ? (
                    <span className="inline-flex items-center gap-1 rounded-md border border-violet-500/20 bg-violet-500/10 px-2 py-0.5 text-[10px] font-semibold text-violet-400">
                        <Building2 className="h-2.5 w-2.5" />
                        {employee.department.name}
                    </span>
                ) : null}
            </div>

            {/* ── Divider ── */}
            <div className="mx-4 border-t border-white/6" />

            {/* ── Contact rows ── */}
            <div className="flex flex-col gap-2 px-4 py-3">
                <div className="flex items-center gap-2 text-xs text-muted-foreground/70 min-w-0">
                    <Mail className="h-3 w-3 shrink-0 text-muted-foreground/40" />
                    <span className="truncate">{employee.work_email ?? '—'}</span>
                </div>
                <div className="flex items-center gap-2 text-xs text-muted-foreground/70 min-w-0">
                    <Phone className="h-3 w-3 shrink-0 text-muted-foreground/40" />
                    <span className="truncate">{employee.phone ?? '—'}</span>
                </div>
            </div>

            {/* ── Footer: Branch + Actions ── */}
            <div className="flex items-center justify-between gap-2 border-t border-white/6 px-3 py-2">
                <div className="min-w-0 text-[10px] text-muted-foreground/45 truncate">
                    {employee.branch?.name ?? '—'}
                </div>
                <div className="flex shrink-0 items-center gap-0.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7 rounded-lg hover:bg-primary/10 hover:text-primary text-muted-foreground/50 transition-colors"
                        onClick={(e) => {
 e.stopPropagation(); router.visit(`/organization/employees/${employee.id}`); 
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
 e.stopPropagation(); onDelete(employee); 
}}
                            title="Delete"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
