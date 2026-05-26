import type { ReactElement, ReactNode } from 'react';
import { PhoneInputWithCountry } from '@/components/phone-input-with-country';
import {
    formatPhoneForDisplay
    
} from '@/lib/phone-with-dial-code';
import type {PhoneCountryOption} from '@/lib/phone-with-dial-code';
import { cn } from '@/lib/utils';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';
export type EmployeeInlinePhoneFieldProps = {
    fieldKey: string;
    label: string;
    value: string;
    fallbackValue?: string | null;
    countries: PhoneCountryOption[];
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    onChange: (value: string) => void;
    error?: string;
    defaultDialCode?: string;
    rowClassName?: string;
    labelClassName?: string;
    canEdit?: boolean;
    highlightMissing?: boolean;
};

export function EmployeeInlinePhoneField({
    fieldKey,
    label,
    value,
    fallbackValue,
    countries,
    activeField,
    setActiveField,
    beginEdit,
    onChange,
    error,
    defaultDialCode,
    rowClassName,
    labelClassName,
    canEdit = true,
    highlightMissing = false,
}: EmployeeInlinePhoneFieldProps): ReactElement {
    const isEditing = activeField === fieldKey;
    const resolved = value || fallbackValue || '';
    const display = formatPhoneForDisplay(resolved, {
        countries,
        fieldKey,
        defaultDialCode,
    });

    let editor: ReactNode;

    if (isEditing) {
        editor = (
            <div>
                <PhoneInputWithCountry
                    countries={countries}
                    value={value}
                    onChange={onChange}
                    fieldKey={fieldKey}
                    defaultDialCode={defaultDialCode}
                    autoFocus
                    onBlur={() => setActiveField(null)}
                />
                {error ? (
                    <div className="mt-1 text-xs text-destructive">{error}</div>
                ) : null}
                {highlightMissing ? (
                    <div className="mt-1 text-xs text-rose-400">Required</div>
                ) : null}
            </div>
        );
    } else {
        editor = (
            <button
                type="button"
                className={cn(
                    'min-w-0 text-left text-sm font-medium text-foreground hover:text-white disabled:cursor-default disabled:hover:text-foreground',
                    highlightMissing && 'text-rose-300',
                )}
                onClick={() => beginEdit(fieldKey)}
                disabled={!canEdit}
            >
                {display}
            </button>
        );
    }

    return (
        <div
            data-employee-field={fieldKey}
            className={cn(
                rowClassName,
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
        >
            <label
                className={cn(
                    labelClassName,
                    highlightMissing && employeeFieldMissingLabelClass,
                )}
            >
                {label}
            </label>
            <div className="min-w-0">{editor}</div>
        </div>
    );
}
