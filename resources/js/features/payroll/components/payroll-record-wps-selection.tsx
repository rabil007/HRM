import { Checkbox } from '@/components/ui/checkbox';
import { DataTableHead } from '@/components/data-table';
import { TableCell } from '@/components/ui/table';
import { cn } from '@/lib/utils';

export function PayrollRecordWpsSelectionHead({
    allSelected,
    someSelected,
    onToggleAll,
}: {
    allSelected: boolean;
    someSelected: boolean;
    onToggleAll: () => void;
}) {
    return (
        <DataTableHead className="w-12 pl-5">
            <Checkbox
                checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                onCheckedChange={onToggleAll}
                aria-label="Select all for WPS export"
            />
        </DataTableHead>
    );
}

export function PayrollRecordWpsSelectionCell({
    checked,
    employeeName,
    onToggle,
}: {
    checked: boolean;
    employeeName: string;
    onToggle: () => void;
}) {
    return (
        <TableCell className={cn('w-12 pl-5', checked && 'bg-primary/5')}>
            <Checkbox
                checked={checked}
                onCheckedChange={onToggle}
                aria-label={`Select ${employeeName} for WPS export`}
            />
        </TableCell>
    );
}
