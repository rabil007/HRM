import type { ReactElement } from 'react';
import { Input } from '@/components/ui/input';
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
}: EditableHeaderNameFieldProps): ReactElement {
    const isEditing = activeField === field && canEdit;

    if (isEditing) {
        return (
            <Input
                className="h-10 rounded-xl border-white/10 bg-white/5 text-white"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onBlur={() => setActiveField(null)}
                autoFocus
                placeholder={placeholder}
            />
        );
    }

    return (
        <button
            type="button"
            className="text-left hover:text-white disabled:cursor-default disabled:opacity-100"
            onClick={() => beginEdit(field)}
            disabled={!canEdit}
        >
            {displayValue}
        </button>
    );
}

// Fix: used canUpdate instead of canEdit - typo in above

export type EditableHeaderPillTextFieldProps = {
    field: string;
    value: string;
    displayValue: string;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onChange: (value: string) => void;
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
}: EditableHeaderPillTextFieldProps): ReactElement {
    const isEditing = activeField === field && canEdit;

    if (isEditing) {
        return (
            <Input
                className="h-8 w-[120px] rounded-full border-white/10 bg-white/5 px-3 text-[10px] font-semibold tracking-wide text-zinc-200"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onBlur={() => setActiveField(null)}
                autoFocus
            />
        );
    }

    return (
        <button
            type="button"
            className="flex items-center gap-2 rounded-full border border-white/[0.08] bg-white/[0.04] px-3 py-1.5 text-[10px] font-bold tracking-widest text-zinc-400 transition-colors hover:border-white/[0.14] hover:text-zinc-200 disabled:cursor-default disabled:hover:text-zinc-400"
            onClick={() => beginEdit(field)}
            disabled={!canEdit}
        >
            {displayValue}
        </button>
    );
}
