import type { ReactElement, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';

export function RequiredIndicator({ show }: { show: boolean }): ReactElement | null {
    if (!show) {
        return null;
    }

    return <span className="text-red-400"> *</span>;
}

type RecordFormFieldProps = {
    field: string;
    highlightMissing: boolean;
    children: ReactNode;
    className?: string;
};

export function RecordFormField({
    field,
    highlightMissing,
    children,
    className,
}: RecordFormFieldProps): ReactElement {
    return (
        <div
            data-record-field={field}
            className={cn(
                'space-y-1.5 rounded-xl',
                highlightMissing && employeeFieldMissingHighlightClass,
                className,
            )}
        >
            {children}
            {highlightMissing ? (
                <p className="text-xs text-rose-400">Required</p>
            ) : null}
        </div>
    );
}

export function recordFieldLabelClass(highlightMissing: boolean): string {
    return cn('text-xs', highlightMissing && employeeFieldMissingLabelClass);
}

export function recordFieldInputClass(highlightMissing: boolean): string {
    return cn(
        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
        highlightMissing && 'border-rose-500/50',
    );
}
