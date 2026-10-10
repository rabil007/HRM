import { Link } from '@inertiajs/react';
import { Briefcase, CheckCircle2, Edit, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { RequirementDetail, RequirementLine } from '@/types/recruitment';

type Props = {
    requirement: RequirementDetail;
    onChangeLineHeadcount: (line: RequirementLine) => void;
};

export function RequirementPositionLinesCard({
    requirement,
    onChangeLineHeadcount,
}: Props) {
    const lines = requirement.lines || [];
    const progress = requirement.progress;

    return (
        <Card className="glass-card border-border/70">
            <CardHeader className="border-b border-border/40 pb-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2 text-base font-bold">
                        <Briefcase className="h-4 w-4 text-primary" />
                        Required Positions ({lines.length})
                    </CardTitle>
                    <div className="flex flex-wrap items-center gap-3 text-xs font-semibold text-muted-foreground">
                        <div className="flex items-center gap-1.5">
                            <Users className="h-3.5 w-3.5" />
                            <span>Target: {requirement.total_headcount}</span>
                        </div>
                        {progress && (
                            <>
                                <span>·</span>
                                <span className="text-emerald-600 dark:text-emerald-400">
                                    Joined: {progress.filled}
                                </span>
                                <span>·</span>
                                <span>
                                    Remaining: {progress.remaining ?? 0}
                                </span>
                                {progress.is_overfilled && (
                                    <Badge
                                        variant="destructive"
                                        className="px-1.5 py-0 text-[10px]"
                                    >
                                        Overfilled
                                    </Badge>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="p-0">
                {progress?.suggest_mark_filled && (
                    <div className="flex items-center gap-2 border-b border-emerald-500/20 bg-emerald-500/10 px-4 py-2.5 text-xs text-emerald-800 dark:text-emerald-200">
                        <CheckCircle2 className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span>
                            Target reached ({progress.filled} /{' '}
                            {progress.target} candidates joined). You can now
                            use Mark Filled when ready.
                        </span>
                    </div>
                )}
                <Table>
                    <TableHeader>
                        <TableRow className="border-border/40 bg-muted/20">
                            <TableHead className="w-[40px]">#</TableHead>
                            <TableHead className="min-w-[180px]">
                                Position Title
                            </TableHead>
                            <TableHead className="min-w-[120px]">
                                Department
                            </TableHead>
                            <TableHead className="w-[90px]">Grade</TableHead>
                            <TableHead className="min-w-[160px]">
                                Salary Range
                            </TableHead>
                            <TableHead className="w-[140px] text-center">
                                Headcount Progress
                            </TableHead>
                            <TableHead className="w-[100px]">Status</TableHead>
                            <TableHead className="min-w-[180px]">
                                Notes / Requirements
                            </TableHead>
                            {requirement.can_change_headcount && (
                                <TableHead className="w-[100px] text-right">
                                    Action
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line, index) => {
                            const joinedCount = line.joined_count ?? 0;
                            const remainingCount =
                                line.remaining_headcount ?? 0;

                            return (
                                <TableRow
                                    key={line.id}
                                    className="border-border/40"
                                >
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {index + 1}
                                    </TableCell>
                                    <TableCell>
                                        <div className="font-semibold text-foreground">
                                            {line.position_title}
                                        </div>
                                        <Link
                                            href={`/organization/recruitment/candidates?requirement_id=${requirement.id}&requirement_line_id=${line.id}`}
                                            className="mt-0.5 inline-block text-[11px] text-primary hover:underline"
                                        >
                                            View candidates ({joinedCount}{' '}
                                            joined)
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {line.department_name || '—'}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {line.grade || '—'}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs font-medium text-foreground">
                                        {line.salary_range_formatted ||
                                            'Not specified'}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        <div className="flex flex-col items-center gap-0.5">
                                            <div className="flex items-center gap-1.5 text-xs font-semibold">
                                                <span>
                                                    {line.required_headcount}{' '}
                                                    req
                                                </span>
                                                <span className="text-muted-foreground">
                                                    ·
                                                </span>
                                                <span className="text-emerald-600 dark:text-emerald-400">
                                                    {joinedCount} joined
                                                </span>
                                            </div>
                                            <span className="text-[11px] text-muted-foreground">
                                                {remainingCount} remaining
                                            </span>
                                            {line.is_overfilled && (
                                                <Badge
                                                    variant="destructive"
                                                    className="mt-0.5 px-1.5 py-0 text-[10px]"
                                                >
                                                    Overfilled (+
                                                    {joinedCount -
                                                        line.required_headcount}
                                                    )
                                                </Badge>
                                            )}
                                            {line.target_reached &&
                                                !line.is_overfilled && (
                                                    <Badge
                                                        variant="outline"
                                                        className="mt-0.5 border-emerald-500/40 px-1.5 py-0 text-[10px] text-emerald-600 dark:text-emerald-400"
                                                    >
                                                        Target reached
                                                    </Badge>
                                                )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'px-2 py-0 text-[10px] font-semibold',
                                                line.status === 'open' &&
                                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                                                line.status === 'on_hold' &&
                                                    'border-amber-500/30 bg-amber-500/10 text-amber-500',
                                                line.status === 'filled' &&
                                                    'border-sky-500/30 bg-sky-500/10 text-sky-500',
                                                line.status === 'cancelled' &&
                                                    'border-rose-500/30 bg-rose-500/10 text-rose-500',
                                            )}
                                        >
                                            {line.status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {line.line_notes || '—'}
                                    </TableCell>
                                    {requirement.can_change_headcount && (
                                        <TableCell className="text-right">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    onChangeLineHeadcount(line)
                                                }
                                                className="h-7 gap-1 px-2 text-xs text-muted-foreground hover:text-foreground"
                                                title="Revise headcount target"
                                            >
                                                <Edit className="h-3.5 w-3.5" />
                                                <span>Revise</span>
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}
