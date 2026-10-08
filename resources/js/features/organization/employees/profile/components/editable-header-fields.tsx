import type { ReactElement, ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';
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
                        'h-10 rounded-xl border-input bg-background/50 text-foreground dark:border-white/10 dark:bg-white/5 dark:text-white',
                        highlightMissing &&
                            'border-rose-500/50 ring-1 ring-rose-500/40',
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
                'rounded-lg px-1 text-left hover:text-primary disabled:cursor-default disabled:opacity-100 dark:hover:text-white',
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
    label?: ReactNode;
    required?: boolean;
    isProvisional?: boolean;
    error?: string | null;
    placeholder?: string;
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
    label,
    required = false,
    isProvisional = false,
    error = null,
    placeholder,
}: EditableHeaderPillTextFieldProps): ReactElement {
    const isEditing = activeField === field && canEdit;
    const showError = Boolean(error) || highlightMissing;
    const helperText =
        error ??
        (highlightMissing
            ? isProvisional
                ? 'Temporary employee ID — enter the official employee number'
                : 'Employee number is required'
            : isProvisional
              ? 'Temporary employee ID — enter the official employee number'
              : null);

    if (isEditing) {
        return (
            <div
                data-employee-field={field}
                className="flex min-w-[11rem] flex-col items-stretch gap-1 md:items-end"
            >
                {label ? (
                    <div className="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                        <span>{label}</span>
                        {required ? (
                            <span
                                className="inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90"
                                aria-hidden
                            />
                        ) : null}
                    </div>
                ) : null}
                <Input
                    className={cn(
                        'h-9 min-w-[10rem] rounded-xl border-input bg-background/50 px-3 text-sm font-semibold tracking-wide text-foreground dark:border-white/10 dark:bg-white/5 dark:text-zinc-200',
                        showError &&
                            'border-rose-500/50 ring-1 ring-rose-500/40',
                    )}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    onBlur={() => setActiveField(null)}
                    autoFocus
                    placeholder={placeholder ?? 'e.g. EMP-1001'}
                    aria-invalid={showError}
                    aria-required={required}
                />
                {helperText ? (
                    <span
                        className={cn(
                            'max-w-[16rem] text-left text-[11px] leading-snug md:text-right',
                            showError
                                ? employeeFieldMissingLabelClass
                                : 'text-muted-foreground',
                        )}
                    >
                        {helperText}
                    </span>
                ) : null}
            </div>
        );
    }

    return (
        <div
            data-employee-field={field}
            className="flex min-w-[11rem] flex-col items-stretch gap-1 md:items-end"
        >
            {label ? (
                <div className="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                    <span>{label}</span>
                    {required ? (
                        <span
                            className="inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90"
                            aria-hidden
                        />
                    ) : null}
                </div>
            ) : null}
            <button
                type="button"
                className={cn(
                    'flex min-h-9 items-center justify-between gap-2 rounded-xl border border-border/80 bg-muted/40 px-3 py-2 text-left transition-colors hover:border-border hover:bg-muted/60 disabled:cursor-default disabled:hover:bg-muted/40 dark:border-white/[0.08] dark:bg-white/[0.04] dark:hover:border-white/[0.14]',
                    showError && employeeFieldMissingHighlightClass,
                    isProvisional &&
                        !showError &&
                        'border-amber-500/30 bg-amber-500/10',
                )}
                onClick={() => beginEdit(field)}
                disabled={!canEdit}
                aria-invalid={showError}
                aria-required={required}
            >
                <span
                    className={cn(
                        'text-sm font-semibold tracking-wide',
                        isProvisional || !displayValue
                            ? 'text-muted-foreground'
                            : 'text-foreground',
                    )}
                >
                    {isProvisional
                        ? 'Temporary ID'
                        : displayValue || 'Enter employee no.'}
                </span>
            </button>
            {helperText ? (
                <span
                    className={cn(
                        'max-w-[16rem] text-left text-[11px] leading-snug md:text-right',
                        showError
                            ? employeeFieldMissingLabelClass
                            : 'text-amber-600 dark:text-amber-400',
                    )}
                >
                    {helperText}
                </span>
            ) : null}
        </div>
    );
}
