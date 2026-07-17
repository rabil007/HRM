import type { ReactElement } from 'react';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { CrewMovementCorrectionValues } from '../types';
import { correctionFieldDisplay, correctionFieldLabel } from '../types';

export function CorrectionValuesTable({
    fields,
    original,
    proposed,
    live,
    proposedLabel = 'Proposed',
}: {
    fields: string[];
    original: CrewMovementCorrectionValues;
    proposed: CrewMovementCorrectionValues;
    live: CrewMovementCorrectionValues;
    proposedLabel?: string;
}): ReactElement {
    return (
        <OrganizationDataTable minWidth="min-w-[720px]" compact>
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead>Field</DataTableHead>
                    <DataTableHead>Original</DataTableHead>
                    <DataTableHead>{proposedLabel}</DataTableHead>
                    <DataTableHead>Current (Live)</DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {fields.map((field) => {
                    const originalDisplay = correctionFieldDisplay(
                        original,
                        field,
                    );
                    const liveDisplay = correctionFieldDisplay(live, field);
                    const hasLive = field in live;
                    const mismatched =
                        hasLive && liveDisplay !== originalDisplay;

                    return (
                        <TableRow
                            key={field}
                            className={dataTableBodyRowClass(false)}
                        >
                            <TableCell className={dataTableCellPrimaryClass()}>
                                {correctionFieldLabel(field)}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {originalDisplay}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {correctionFieldDisplay(proposed, field)}
                            </TableCell>
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    mismatched &&
                                        'font-medium text-amber-600 dark:text-amber-400',
                                )}
                            >
                                {liveDisplay}
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </OrganizationDataTable>
    );
}
