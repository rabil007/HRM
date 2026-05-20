import type { ReactElement, ReactNode } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { PhoneInputWithCountry } from '@/components/phone-input-with-country';
import { Input } from '@/components/ui/input';
import type { CountryOption } from '@/features/organization/employees/types';
import { formatPhoneForDisplay } from '@/lib/phone-with-dial-code';
import { cn } from '@/lib/utils';

export const personalFieldRowClass =
    'grid grid-cols-1 gap-2 rounded-xl border border-transparent px-3 py-2.5 transition-colors hover:border-white/[0.06] hover:bg-white/[0.03] sm:grid-cols-[minmax(0,9.5rem)_1fr] sm:items-center sm:gap-5';

export const personalFieldLabelClass =
    'text-[11px] font-medium uppercase tracking-wider text-zinc-500';

export type PersonalEditableTextRowProps = {
    label: string;
    field: string;
    value: string;
    displayValue: string;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    onChange: (value: string) => void;
    error?: string;
    inputType?: 'text' | 'date';
};

export function PersonalEditableTextRow({
    label,
    field,
    value,
    displayValue,
    activeField,
    setActiveField,
    beginEdit,
    onChange,
    error,
    inputType = 'text',
}: PersonalEditableTextRowProps): ReactElement {
    return (
        <div className={personalFieldRowClass}>
            <label className={personalFieldLabelClass}>{label}</label>
            {activeField === field ? (
                <div>
                    <Input
                        type={inputType}
                        className="h-10 rounded-xl border-white/5 bg-white/5"
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                        onBlur={() => setActiveField(null)}
                        autoFocus
                    />
                    {error ? (
                        <div className="mt-1 text-xs text-destructive">{error}</div>
                    ) : null}
                </div>
            ) : (
                <button
                    type="button"
                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                    onClick={() => beginEdit(field)}
                >
                    {displayValue}
                </button>
            )}
        </div>
    );
}

export type PersonalFieldRowProps = {
    label: string;
    children: ReactNode;
    className?: string;
};

export function PersonalFieldRow({
    label,
    children,
    className,
}: PersonalFieldRowProps): ReactElement {
    return (
        <div className={cn(personalFieldRowClass, className)}>
            <label className={personalFieldLabelClass}>{label}</label>
            <div className="min-w-0 text-sm font-medium text-zinc-100">{children}</div>
        </div>
    );
}

export type PersonalEditablePhoneRowProps = {
    label: string;
    field: string;
    value: string;
    fallbackValue?: string | null;
    countries: CountryOption[];
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    onChange: (value: string) => void;
    error?: string;
    defaultDialCode?: string;
};

export function PersonalEditablePhoneRow({
    label,
    field,
    value,
    fallbackValue,
    countries,
    activeField,
    setActiveField,
    beginEdit,
    onChange,
    error,
    defaultDialCode,
}: PersonalEditablePhoneRowProps): ReactElement {
    const displayValue = formatPhoneForDisplay(value || fallbackValue, {
        countries,
        fieldKey: field,
        defaultDialCode,
    });

    return (
        <div className={personalFieldRowClass}>
            <label className={personalFieldLabelClass}>{label}</label>
            {activeField === field ? (
                <div>
                    <PhoneInputWithCountry
                        countries={countries}
                        value={value}
                        onChange={onChange}
                        fieldKey={field}
                        defaultDialCode={defaultDialCode}
                        autoFocus
                        onBlur={() => setActiveField(null)}
                    />
                    {error ? (
                        <div className="mt-1 text-xs text-destructive">{error}</div>
                    ) : null}
                </div>
            ) : (
                <button
                    type="button"
                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                    onClick={() => beginEdit(field)}
                >
                    {displayValue}
                </button>
            )}
        </div>
    );
}

export type PersonalEditableSelectRowProps = {
    label: string;
    field: string;
    value: string;
    displayValue: string;
    options: Array<{ id: number; label: string; value: string }>;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    onChange: (value: string) => void;
    error?: string;
    className?: string;
};

export function PersonalEditableSelectRow({
    label,
    field,
    value,
    displayValue,
    options,
    activeField,
    setActiveField,
    beginEdit,
    onChange,
    error,
    className,
}: PersonalEditableSelectRowProps): ReactElement {
    return (
        <div className={cn(personalFieldRowClass, className)}>
            <label className={personalFieldLabelClass}>{label}</label>
            {activeField === field ? (
                <div>
                    <AppSelect
                        value={value}
                        onValueChange={(next) => {
                            onChange(next);
                            setActiveField(null);
                        }}
                        onClose={() => setActiveField(null)}
                        variant="dark"
                        placeholder="—"
                    >
                        <AppSelectItem value="">—</AppSelectItem>
                        {options.map((option) => (
                            <AppSelectItem key={option.id} value={option.value}>
                                {option.label}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                    {error ? (
                        <div className="mt-1 text-xs text-destructive">{error}</div>
                    ) : null}
                </div>
            ) : (
                <button
                    type="button"
                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                    onClick={() => beginEdit(field)}
                >
                    {displayValue}
                </button>
            )}
        </div>
    );
}
