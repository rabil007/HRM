import type { ReactElement, ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';

export type EditableDetailFieldProps = {
    label: ReactNode;
    field: string;
    displayValue: string;
    activeField: string | null;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    editControl: ReactNode;
    className?: string;
    highlightMissing?: boolean;
};

export function EditableDetailField({
    label,
    field,
    displayValue,
    activeField,
    beginEdit,
    canEdit,
    editControl,
    className,
    highlightMissing = false,
}: EditableDetailFieldProps): ReactElement {
    const isEditing = activeField === field && canEdit;

    return (
        <div
            data-employee-field={field}
            className={cn(
                'group px-4 py-4 transition-colors hover:bg-white/[0.03]',
                highlightMissing && employeeFieldMissingHighlightClass,
                className,
            )}
        >
            <div
                className={cn(
                    'mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500',
                    highlightMissing && employeeFieldMissingLabelClass,
                )}
            >
                {label}
            </div>
            {isEditing ? (
                <div>
                    {editControl}
                    {highlightMissing ? (
                        <div className="mt-1 text-xs text-rose-400">Required</div>
                    ) : null}
                </div>
            ) : (
                <button
                    type="button"
                    className={cn(
                        'min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200',
                        highlightMissing && 'text-rose-300',
                    )}
                    onClick={() => beginEdit(field)}
                    disabled={!canEdit}
                >
                    {displayValue}
                </button>
            )}
        </div>
    );
}

export type EditableDetailTextFieldProps = {
    label: ReactNode;
    field: string;
    value: string;
    displayValue: string;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onChange: (value: string) => void;
    inputType?: 'text' | 'date' | 'email';
    inputClassName?: string;
    highlightMissing?: boolean;
};

export function EditableDetailTextField({
    label,
    field,
    value,
    displayValue,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onChange,
    inputType = 'text',
    inputClassName = 'h-8 rounded-lg border-white/10 bg-white/5 text-zinc-200',
    highlightMissing = false,
}: EditableDetailTextFieldProps): ReactElement {
    return (
        <EditableDetailField
            label={label}
            field={field}
            displayValue={displayValue}
            activeField={activeField}
            beginEdit={beginEdit}
            canEdit={canEdit}
            highlightMissing={highlightMissing}
            editControl={
                <Input
                    type={inputType}
                    className={cn(
                        inputClassName,
                        highlightMissing && 'border-rose-500/50',
                    )}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    onBlur={() => setActiveField(null)}
                    autoFocus
                />
            }
        />
    );
}
