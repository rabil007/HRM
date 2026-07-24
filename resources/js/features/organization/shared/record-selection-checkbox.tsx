import { DataTableHead } from '@/components/data-table';
import { Checkbox } from '@/components/ui/checkbox';
import { TableCell } from '@/components/ui/table';

export function RecordSelectionHead({
    checked,
    indeterminate,
    onToggle,
}: {
    checked: boolean;
    indeterminate: boolean;
    onToggle: () => void;
}) {
    return (
        <DataTableHead className="w-12 px-3 text-center">
            <Checkbox
                checked={indeterminate ? 'indeterminate' : checked}
                onCheckedChange={onToggle}
                aria-label="Select all visible records"
            />
        </DataTableHead>
    );
}

export function RecordSelectionCell({
    checked,
    onToggle,
    label,
}: {
    checked: boolean;
    onToggle: () => void;
    label: string;
}) {
    return (
        <TableCell
            className="w-12 px-3 text-center"
            onClick={(event) => event.stopPropagation()}
        >
            <Checkbox
                checked={checked}
                onCheckedChange={onToggle}
                aria-label={label}
            />
        </TableCell>
    );
}
