import { Briefcase, Edit, Users } from 'lucide-react';
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

    return (
        <Card className="glass-card border-border/70">
            <CardHeader className="border-b border-border/40 pb-4">
                <div className="flex items-center justify-between">
                    <CardTitle className="flex items-center gap-2 text-base font-bold">
                        <Briefcase className="h-4 w-4 text-primary" />
                        Required Positions ({lines.length})
                    </CardTitle>
                    <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground">
                        <Users className="h-3.5 w-3.5" />
                        <span>
                            Total Headcount: {requirement.total_headcount}
                        </span>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="p-0">
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
                            <TableHead className="w-[120px] text-center">
                                Headcount
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
                        {lines.map((line, index) => (
                            <TableRow
                                key={line.id}
                                className="border-border/40"
                            >
                                <TableCell className="font-mono text-xs text-muted-foreground">
                                    {index + 1}
                                </TableCell>
                                <TableCell className="font-semibold text-foreground">
                                    {line.position_title}
                                </TableCell>
                                <TableCell className="text-xs text-muted-foreground">
                                    {line.department_name || '—'}
                                </TableCell>
                                <TableCell className="font-mono text-xs text-muted-foreground">
                                    {line.grade || '—'}
                                </TableCell>
                                <TableCell className="text-center">
                                    <Badge
                                        variant="secondary"
                                        className="px-2 py-0.5 text-xs font-bold"
                                    >
                                        {line.required_headcount}
                                    </Badge>
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
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}
