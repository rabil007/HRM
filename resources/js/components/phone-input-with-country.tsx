import type { FocusEvent, ReactElement } from 'react';
import { useMemo } from 'react';
import { Input } from '@/components/ui/input';
import {
    combinePhoneWithDialCode,
    countriesWithDialCode,
    defaultDialCodeForPhoneField,
    parsePhoneWithDialCode,
} from '@/lib/phone-with-dial-code';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { cn } from '@/lib/utils';

export type PhoneInputWithCountryProps = {
    countries: PhoneCountryOption[];
    value: string;
    onChange: (value: string) => void;
    onBlur?: () => void;
    fieldKey?: string;
    defaultDialCode?: string;
    className?: string;
    selectClassName?: string;
    inputClassName?: string;
    autoFocus?: boolean;
    disabled?: boolean;
    id?: string;
};

export function PhoneInputWithCountry({
    countries,
    value,
    onChange,
    onBlur,
    fieldKey,
    defaultDialCode,
    className,
    selectClassName,
    inputClassName,
    autoFocus = false,
    disabled = false,
    id,
}: PhoneInputWithCountryProps): ReactElement {
    const dialCountries = useMemo(
        () => countriesWithDialCode(countries),
        [countries],
    );

    const fallbackDialCode = useMemo(() => {
        if (defaultDialCode) {
            return defaultDialCode;
        }

        if (fieldKey) {
            return defaultDialCodeForPhoneField(fieldKey, countries);
        }

        return dialCountries[0]?.dial_code ?? '';
    }, [countries, defaultDialCode, dialCountries, fieldKey]);

    const { dialCode, nationalNumber } = parsePhoneWithDialCode(value, countries);
    const effectiveDialCode = dialCode || fallbackDialCode;

    const handleContainerBlur = (event: FocusEvent<HTMLDivElement>): void => {
        const container = event.currentTarget;

        window.setTimeout(() => {
            const active = document.activeElement;

            if (container.contains(active)) {
                return;
            }

            onBlur?.();
        }, 0);
    };

    return (
        <div className={cn('flex min-w-0 gap-2', className)} onBlur={handleContainerBlur}>
            <select
                aria-label="Country code"
                disabled={disabled}
                value={effectiveDialCode}
                onChange={(event) => {
                    onChange(
                        combinePhoneWithDialCode(
                            event.target.value,
                            nationalNumber,
                        ),
                    );
                }}
                className={cn(
                    'h-10 shrink-0 rounded-xl border border-white/10 bg-white/5 px-2 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary',
                    selectClassName,
                )}
            >
                <option value="">Code</option>
                {dialCountries.map((country) => (
                    <option key={country.id} value={country.dial_code!}>
                        {country.dial_code} {country.code}
                    </option>
                ))}
            </select>
            <Input
                id={id}
                type="tel"
                inputMode="tel"
                autoFocus={autoFocus}
                disabled={disabled}
                placeholder="Phone number"
                className={cn(
                    'h-10 min-w-0 flex-1 rounded-xl border-white/5 bg-white/5',
                    inputClassName,
                )}
                value={nationalNumber}
                onChange={(event) => {
                    onChange(
                        combinePhoneWithDialCode(
                            effectiveDialCode,
                            event.target.value,
                        ),
                    );
                }}
            />
        </div>
    );
}
