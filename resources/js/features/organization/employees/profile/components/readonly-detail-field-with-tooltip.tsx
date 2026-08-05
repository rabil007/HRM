import type { ReactElement, ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';

export type ReadOnlyDetailFieldWithTooltipProps = {
    label: ReactNode;
    field: string;
    displayValue: string;
    tooltip: ReactNode;
    highlightMissing?: boolean;
};

export function ReadOnlyDetailFieldWithTooltip({
    label,
    field,
    displayValue,
    tooltip,
    highlightMissing = false,
}: ReadOnlyDetailFieldWithTooltipProps): ReactElement {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div
                    data-employee-field={field}
                    className={cn(
                        'group cursor-default px-4 py-4 transition-colors',
                        highlightMissing && employeeFieldMissingHighlightClass,
                    )}
                >
                    <div
                        className={cn(
                            'mb-1.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase',
                            highlightMissing && employeeFieldMissingLabelClass,
                        )}
                    >
                        {label}
                    </div>
                    <div className="truncate text-sm font-medium text-foreground dark:text-zinc-200">
                        {displayValue}
                    </div>
                </div>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs">
                {tooltip}
            </TooltipContent>
        </Tooltip>
    );
}
