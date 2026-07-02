import { dataTableCellClass } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { TableCell } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { SalaryInput } from '../types';
import { formatTimesheetAmount } from '../types';

export function PayrollRecordSalaryInputsCell({
    inputs,
}: {
    inputs: SalaryInput[];
}) {
    return (
        <TableCell className={dataTableCellClass()}>
            {inputs.length === 0 ? null : (
                <div className="space-y-1.5">
                    {inputs.map((input) => (
                        <div
                            key={input.id}
                            className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs"
                        >
                            <span className="font-medium">
                                {input.type_label ?? 'Salary input'}
                            </span>
                            <Badge
                                variant="outline"
                                className={cn(
                                    'px-1.5 py-0 text-[10px] font-semibold tabular-nums',
                                    input.is_addition
                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
                                        : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200',
                                )}
                            >
                                {input.is_addition ? '+' : '−'}
                                {formatTimesheetAmount(input.amount)}
                            </Badge>
                        </div>
                    ))}
                </div>
            )}
        </TableCell>
    );
}
