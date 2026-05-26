import type { ReactElement } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { employeeFieldMissingHighlightClass } from '@/pages/organization/_lib/employee-required-field-labels';
export type EditableHeaderNameFieldProps = {
    field: string;
    value: string;
    displayValue: string;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onChange: (value: string) => void;
    placeholder?: string;
    highlightMissing?: boolean;
};

export function EditableHeaderNameField({
    field,
    value,
    displayValue,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onChange,
    placeholder = 'Name',
    highlightMissing = false,
}: EditableHeaderNameFieldProps): ReactElement {
    const isEditing = activeField === field && canEdit;

    if (isEditing) {
        return (
            <div data-employee-field={field} className="space-y-1">
            <Input
                className={cn(
                    'h-10 rounded-xl border-white/10 bg-white/5 text-white',
                    highlightMissing && 'border-rose-500/50 ring-1 ring-rose-500/40',
                )}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onBlur={() => setActiveField(null)}
                autoFocus
                placeholder={placeholder}
            />
            {highlightMissing ? (
                <span className="text-xs text-rose-400">Required</span>
            ) : null}
            </div>
        );
    }

    return (
        <button
            type="button"
            data-employee-field={field}
            className={cn(
                'rounded-lg px-1 text-left hover:text-white disabled:cursor-default disabled:opacity-100',
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
            onClick={() => beginEdit(field)}
            disabled={!canEdit}
        >
            {displayValue}
        </button>
    );
}

export type EditableHeaderPillTextFieldProps = {
    field: string;
    value: string;
    displayValue: string;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onChange: (value: string) => void;
    highlightMissing?: boolean;
};

export function EditableHeaderPillTextField({
    field,
    value,
    displayValue,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onChange,
    highlightMissing = false,
}: EditableHeaderPillTextFieldProps): ReactElement {
    const isEditing = activeField === field && canEdit;

    if (isEditing) {
        return (
            <div data-employee-field={field}>
            <Input
                className={cn(
                    'h-8 w-[120px] rounded-full border-white/10 bg-white/5 px-3 text-[10px] font-semibold tracking-wide text-zinc-200',
                    highlightMissing && 'border-rose-500/50 ring-1 ring-rose-500/40',
                )}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onBlur={() => setActiveField(null)}
                autoFocus
            />
            </div>
        );
    }

    return (
        <button
            type="button"
            data-employee-field={field}
            className={cn(
                'flex items-center gap-2 rounded-full border border-white/[0.08] bg-white/[0.04] px-3 py-1.5 text-[10px] font-bold tracking-widest text-zinc-400 transition-colors hover:border-white/[0.14] hover:text-zinc-200 disabled:cursor-default disabled:hover:text-zinc-400',
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
            onClick={() => beginEdit(field)}
            disabled={!canEdit}
        >
            {displayValue}
        </button>
    );
}
