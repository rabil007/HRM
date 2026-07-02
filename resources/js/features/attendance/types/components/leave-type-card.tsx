import { Eye, Pencil, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import type { LeaveType } from '../types';

export function LeaveTypeCard({
    leaveType,
    onEdit,
    onDelete,
    onToggleStatus,
}: {
    leaveType: LeaveType;
    onEdit: (leaveType: LeaveType) => void;
    onDelete: (leaveType: LeaveType) => void;
    onToggleStatus: (leaveType: LeaveType, enabled: boolean) => void;
}) {
    const statusClass =
        leaveType.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200'
            : 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20';

    return (
        <Card className="group relative overflow-hidden glass-card transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="line-clamp-1 text-lg font-extrabold tracking-tight">
                            {leaveType.name}
                        </CardTitle>
                        <CardDescription className="mt-2 text-sm font-medium text-muted-foreground/85">
                            {leaveType.days_per_year} days per year
                        </CardDescription>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Badge
                                variant="secondary"
                                className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                            >
                                {leaveType.code}
                            </Badge>
                            {leaveType.carry_forward ? (
                                <Badge
                                    variant="secondary"
                                    className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                                >
                                    Carry forward
                                </Badge>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex flex-col items-end gap-2">
                        <Badge
                            className={`border text-[10px] font-bold tracking-wider uppercase ${statusClass}`}
                        >
                            {leaveType.status}
                        </Badge>
                        <span
                            className="inline-block h-5 w-5 rounded-full border border-border/60"
                            style={{
                                backgroundColor: leaveType.color ?? '#94a3b8',
                            }}
                        />
                    </div>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-12">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="text-xs font-semibold text-muted-foreground/80">
                            Max carry days
                        </div>
                        <div className="text-sm font-bold tabular-nums">
                            {leaveType.max_carry_days}
                        </div>
                    </div>
                </div>
            </CardContent>

            <div className="pointer-events-none absolute right-4 bottom-4 left-4">
                <div className="pointer-events-auto flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
                    <div className="flex items-center gap-2 pl-1.5">
                        <Switch
                            checked={leaveType.status === 'active'}
                            onCheckedChange={(checked) =>
                                onToggleStatus(leaveType, checked)
                            }
                        />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/70 uppercase">
                            Active
                        </span>
                    </div>

                    <div className="flex items-center justify-end gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                            asChild
                            title="View"
                        >
                            <a href={`/attendance/types/${leaveType.id}`}>
                                <Eye className="h-4 w-4" />
                            </a>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                            onClick={() => onEdit(leaveType)}
                            title="Edit"
                        >
                            <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => onDelete(leaveType)}
                            title="Delete"
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </Card>
    );
}
