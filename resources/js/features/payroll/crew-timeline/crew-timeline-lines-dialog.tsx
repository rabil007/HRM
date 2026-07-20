import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import type { CrewTimelineEmployeeSummary } from './types';

export function CrewTimelineLinesDialog({
    employee,
    open,
    onOpenChange,
}: {
    employee: CrewTimelineEmployeeSummary | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (!employee) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-5xl overflow-y-auto glass-card">
                <DialogHeader>
                    <DialogTitle>
                        {employee.employee_name ?? 'Employee'} timeline lines
                    </DialogTitle>
                    <DialogDescription>
                        {[
                            employee.employee_number,
                            employee.assignment_number,
                            employee.vessel,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </DialogDescription>
                </DialogHeader>
                <Table className="min-w-[960px]">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Phase</TableHead>
                            <TableHead>Pay category</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead>To</TableHead>
                            <TableHead>Days</TableHead>
                            <TableHead>Actual start</TableHead>
                            <TableHead>Actual end</TableHead>
                            <TableHead>Warning</TableHead>
                            <TableHead>Remarks</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {employee.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>{line.phase_label ?? '—'}</TableCell>
                                <TableCell>
                                    {line.pay_category_label ?? '—'}
                                </TableCell>
                                <TableCell>
                                    {formatDisplayDate(line.from_date)}
                                </TableCell>
                                <TableCell>
                                    {formatDisplayDate(line.to_date)}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {line.days}
                                </TableCell>
                                <TableCell>
                                    {formatDisplayDate(
                                        line.source_actual_start,
                                    )}
                                </TableCell>
                                <TableCell>
                                    {formatDisplayDate(line.source_actual_end)}
                                </TableCell>
                                <TableCell>
                                    {line.warning ? (
                                        <Badge
                                            variant="outline"
                                            className={
                                                line.warning.is_blocking
                                                    ? 'border-red-500/40 text-red-700 dark:text-red-300'
                                                    : 'border-amber-500/40 text-amber-700 dark:text-amber-300'
                                            }
                                        >
                                            {line.warning.label}
                                        </Badge>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell className="max-w-[220px] text-sm whitespace-normal text-muted-foreground">
                                    {line.remarks ?? '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </DialogContent>
        </Dialog>
    );
}
