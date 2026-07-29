import { Pencil, Star, Trash2 } from 'lucide-react';
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
import type { LeaveApprovalPolicy } from '../types';

export function LeaveApprovalPolicyCard({
    policy,
    canUpdate,
    canDelete,
    onEdit,
    onDelete,
    onToggleStatus,
    onSetDefault,
}: {
    policy: LeaveApprovalPolicy;
    canUpdate: boolean;
    canDelete: boolean;
    onEdit: (policy: LeaveApprovalPolicy) => void;
    onDelete: (policy: LeaveApprovalPolicy) => void;
    onToggleStatus: (policy: LeaveApprovalPolicy, enabled: boolean) => void;
    onSetDefault: (policy: LeaveApprovalPolicy) => void;
}) {
    const statusClass =
        policy.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200'
            : 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20';

    return (
        <Card className="group relative overflow-hidden glass-card transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="line-clamp-1 text-lg font-extrabold tracking-tight">
                            {policy.name}
                        </CardTitle>
                        <CardDescription className="mt-2 line-clamp-2 text-sm font-medium text-muted-foreground/85">
                            {policy.description?.trim()
                                ? policy.description
                                : `${policy.steps.length} approval step${policy.steps.length === 1 ? '' : 's'}`}
                        </CardDescription>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {policy.is_default ? (
                                <Badge
                                    variant="secondary"
                                    className="border-amber-500/20 bg-amber-500/10 text-[10px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-200"
                                >
                                    Default
                                </Badge>
                            ) : null}
                            <Badge
                                variant="secondary"
                                className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                            >
                                {policy.steps.length} step
                                {policy.steps.length === 1 ? '' : 's'}
                            </Badge>
                            <Badge
                                variant="secondary"
                                className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                            >
                                {policy.departments_count} dept
                                {policy.departments_count === 1 ? '' : 's'}
                            </Badge>
                        </div>
                    </div>
                    <Badge
                        className={`border text-[10px] font-bold tracking-wider uppercase ${statusClass}`}
                    >
                        {policy.status}
                    </Badge>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-12">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="text-xs font-semibold text-muted-foreground/80">
                            Departments
                        </div>
                        <div className="text-sm font-bold tabular-nums">
                            {policy.departments_count}
                        </div>
                    </div>
                </div>
            </CardContent>

            <div className="pointer-events-none absolute right-4 bottom-4 left-4">
                <div className="pointer-events-auto flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
                    <div className="flex items-center gap-2 pl-1.5">
                        <Switch
                            checked={policy.status === 'active'}
                            disabled={!canUpdate || policy.is_default}
                            onCheckedChange={(checked) =>
                                onToggleStatus(policy, checked)
                            }
                        />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/70 uppercase">
                            Active
                        </span>
                    </div>

                    <div className="flex items-center justify-end gap-1">
                        {canUpdate && !policy.is_default ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                                onClick={() => onSetDefault(policy)}
                                title="Set as default"
                            >
                                <Star className="h-4 w-4" />
                            </Button>
                        ) : null}
                        {canUpdate ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                                onClick={() => onEdit(policy)}
                                title="Edit"
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                        ) : null}
                        {canDelete &&
                        policy.can_delete !== false &&
                        !policy.is_default ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                                onClick={() => onDelete(policy)}
                                title={policy.delete_blocked_reason ?? 'Delete'}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
        </Card>
    );
}
