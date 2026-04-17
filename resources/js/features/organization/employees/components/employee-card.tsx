import { router } from '@inertiajs/react';
import { Clock, Mail, Phone, User2 } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { Employee } from '../types';

function getBadgeColor(text: string) {
    const colors = [
        'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 shadow-[0_0_15px_rgba(16,185,129,0.1)]',
        'bg-blue-500/20 text-blue-400 border border-blue-500/30 shadow-[0_0_15px_rgba(59,130,246,0.1)]',
        'bg-purple-500/20 text-purple-400 border border-purple-500/30 shadow-[0_0_15px_rgba(168,85,247,0.1)]',
        'bg-amber-500/20 text-amber-400 border border-amber-500/30 shadow-[0_0_15px_rgba(245,158,11,0.1)]',
        'bg-rose-500/20 text-rose-400 border border-rose-500/30 shadow-[0_0_15px_rgba(244,63,94,0.1)]',
        'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30 shadow-[0_0_15_rgba(6,182,212,0.1)]',
    ];

    if (!text || text === 'No Position') {
return 'bg-zinc-800/50 text-zinc-400 border border-zinc-700/50';
}

    const hash = text.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);

    return colors[hash % colors.length];
}

export function EmployeeCard({
    employee,
}: {
    employee: Employee;
}) {
    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const positionTitle = employee.position?.title ?? 'No Position';
    const badgeColor = getBadgeColor(positionTitle);

    return (
        <Card
            className="group overflow-hidden relative cursor-pointer border-border/60 hover:border-primary/40 transition-colors duration-300 bg-card/30 hover:bg-card/40 shadow-[0_8px_30px_rgb(0,0,0,0.35)] rounded-2xl"
            onClick={() => router.visit(`/organization/employees/${employee.id}`)}
        >
            <div className="flex h-[176px] relative z-10">
                {/* Image Section */}
                <div className="w-[120px] shrink-0 relative overflow-hidden bg-muted/20 border-r border-border/50">
                    {imageSrc ? (
                        <img
                            src={imageSrc}
                            alt={employee.name}
                            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                        />
                    ) : (
                        <div className="h-full w-full flex items-center justify-center bg-muted/10">
                            <User2 className="h-10 w-10 text-muted-foreground/30" />
                        </div>
                    )}

                    <div className="absolute top-2 left-2">
                        <div className="flex items-center gap-1.5 px-2 py-1 rounded-full bg-background/60 backdrop-blur border border-border/60">
                            <div
                                className={cn(
                                    'h-2 w-2 rounded-full',
                                    employee.status === 'active'
                                        ? 'bg-emerald-500'
                                        : employee.status === 'on_leave'
                                          ? 'bg-amber-500'
                                          : employee.status === 'inactive'
                                            ? 'bg-muted-foreground'
                                            : 'bg-rose-500',
                                )}
                            />
                            <span className="text-[9px] font-bold uppercase tracking-wider text-foreground/80">
                                {employee.status.replace('_', ' ')}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Content Section */}
                <div className="flex-1 px-4 py-3 flex flex-col min-w-0 relative">
                    <div>
                        <div
                            className="text-base font-semibold tracking-tight truncate text-foreground"
                            title={employee.name}
                        >
                            {employee.name}
                        </div>
                        <div className="mt-0.5 text-[10px] font-mono font-semibold text-muted-foreground tracking-[0.16em] uppercase">
                            {employee.employee_no}
                        </div>
                    </div>

                    <div className="mt-3 space-y-2 min-w-0">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground min-w-0">
                            <div className="h-7 w-7 rounded-lg bg-muted/30 border border-border/60 flex items-center justify-center shrink-0">
                                <Mail className="h-3.5 w-3.5" />
                            </div>
                            <span className="truncate">{employee.work_email ?? '—'}</span>
                        </div>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground min-w-0">
                            <div className="h-7 w-7 rounded-lg bg-muted/30 border border-border/60 flex items-center justify-center shrink-0">
                                <Phone className="h-3.5 w-3.5" />
                            </div>
                            <span className="truncate">{employee.phone ?? '—'}</span>
                        </div>
                    </div>

                    <div className="mt-auto pt-3 border-t border-border/60 flex items-center justify-between gap-3">
                        <div
                            className={cn(
                                'px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide border truncate max-w-[70%]',
                                badgeColor,
                            )}
                            title={positionTitle}
                        >
                            {positionTitle}
                        </div>
                        <div className="flex items-center gap-1.5 text-muted-foreground/70 group-hover:text-primary/70 transition-colors">
                            <span className="text-[10px] font-semibold tracking-wider uppercase hidden sm:inline">
                                Details
                            </span>
                            <Clock className="h-3.5 w-3.5" />
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    );
}
