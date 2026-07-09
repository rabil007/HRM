import { router } from '@inertiajs/react';
import { Cake, CalendarDays, Eye, Mail, Phone, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeStatusBadge } from '@/features/organization/employees/components/employee-status-badge';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { Employee } from '../types';

const POSITION_COLORS = [
    'border-indigo-500/20 bg-indigo-500/10 text-indigo-600 dark:border-indigo-500/30 dark:bg-indigo-500/15 dark:text-indigo-400',
    'border-violet-500/20 bg-violet-500/10 text-violet-600 dark:border-violet-500/30 dark:bg-violet-500/15 dark:text-violet-400',
    'border-sky-500/20 bg-sky-500/10 text-sky-600 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-400',
    'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-400',
    'border-amber-500/20 bg-amber-500/10 text-amber-600 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-400',
    'border-rose-500/20 bg-rose-500/10 text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/15 dark:text-rose-400',
];

export function EmployeeCard({
    employee,
    showUrl,
    onDelete,
}: {
    employee: Employee;
    showUrl: string;
    onDelete?: (employee: Employee) => void;
}) {
    const positionColor =
        POSITION_COLORS[employee.name.length % POSITION_COLORS.length];
    const birthdayDisplay = formatDisplayDate(employee.date_of_birth);
    const birthday = birthdayDisplay !== '—' ? birthdayDisplay : null;
    const hireDateDisplay = formatDisplayDate(employee.hire_date);
    const hireDate = hireDateDisplay !== '—' ? hireDateDisplay : null;

    return (
        <div
            className="group relative flex cursor-pointer overflow-hidden rounded-2xl border border-border/80 bg-card/80 shadow-sm backdrop-blur-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
            onClick={() => router.visit(showUrl)}
        >
            <EmployeeAvatar
                name={employee.name}
                image={employee.image}
                size="card"
                className="w-28 shrink-0 self-stretch rounded-none border-0"
            />

            {/* ── Right: Details panel ── */}
            <div className="flex min-w-0 flex-1 flex-col justify-between p-3">
                {/* Name + ID + Status */}
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div
                            className="truncate text-sm leading-tight font-bold tracking-wide text-foreground uppercase"
                            title={employee.name}
                        >
                            {employee.name}
                        </div>
                        <span className="mt-1 inline-block rounded-md bg-muted px-1.5 py-0.5 font-mono text-[10px] font-semibold text-muted-foreground dark:bg-white/8">
                            {employee.employee_no}
                        </span>
                    </div>
                    <div className="shrink-0">
                        <EmployeeStatusBadge status={employee.status} />
                    </div>
                </div>

                {/* Contact rows */}
                <div className="mt-2 flex flex-col gap-1.5">
                    <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                        <Mail className="h-3 w-3 shrink-0 text-primary/60" />
                        <span className="truncate">
                            {employee.work_email ?? '—'}
                        </span>
                    </div>
                    <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                        <Phone className="h-3 w-3 shrink-0 text-primary/60" />
                        <span className="truncate">
                            {employee.phone ?? '—'}
                        </span>
                    </div>
                    {hireDate ? (
                        <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                            <CalendarDays className="h-3 w-3 shrink-0 text-primary/60" />
                            <span>{hireDate}</span>
                        </div>
                    ) : null}
                    {birthday ? (
                        <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
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
                                'inline-flex items-center truncate rounded-md border px-2 py-0.5 text-[10px] font-semibold',
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
                            className="h-7 w-7 rounded-lg text-muted-foreground/50 transition-colors hover:bg-primary/10 hover:text-primary"
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
                                className="h-7 w-7 rounded-lg text-muted-foreground/50 transition-colors hover:bg-destructive/10 hover:text-destructive"
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
