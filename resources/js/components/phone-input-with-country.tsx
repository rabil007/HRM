import { ChevronDown } from 'lucide-react';
import type { FocusEvent, ReactElement } from 'react';
import { useMemo, useRef, useState } from 'react';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
    nationalPlaceholder?: string;
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
    nationalPlaceholder = 'Phone number',
}: PhoneInputWithCountryProps): ReactElement {
    const countryPickerOpenRef = useRef(false);
    const [countryOpen, setCountryOpen] = useState(false);

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

    const { dialCode, nationalNumber } = parsePhoneWithDialCode(
        value,
        countries,
    );
    const effectiveDialCode = dialCode || fallbackDialCode;

    const selectedCountry = useMemo(
        () =>
            dialCountries.find(
                (country) => country.dial_code === effectiveDialCode,
            ),
        [dialCountries, effectiveDialCode],
    );

    const handleCountryOpenChange = (open: boolean): void => {
        countryPickerOpenRef.current = open;
        setCountryOpen(open);
    };

    const handleContainerBlur = (event: FocusEvent<HTMLDivElement>): void => {
        const container = event.currentTarget;

        window.setTimeout(() => {
            if (countryPickerOpenRef.current) {
                return;
            }

            const active = document.activeElement;

            if (container.contains(active)) {
                return;
            }

            const popoverContent = document.querySelector(
                '[data-slot="phone-country-popover"]',
            );

            if (popoverContent?.contains(active)) {
                return;
            }

            onBlur?.();
        }, 0);
    };

    return (
        <div
            className={cn('flex min-w-0 gap-2', className)}
            onBlur={handleContainerBlur}
        >
            <Popover open={countryOpen} onOpenChange={handleCountryOpenChange}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        disabled={disabled}
                        aria-label="Country code"
                        className={cn(
                            'inline-flex h-10 shrink-0 items-center gap-1 rounded-xl border border-input bg-background/80 px-2 text-sm text-foreground outline-none hover:bg-muted focus-visible:ring-1 focus-visible:ring-primary disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-100 dark:hover:bg-white/10',
                            selectClassName,
                        )}
                    >
                        <span className="max-w-[5.5rem] truncate">
                            {effectiveDialCode
                                ? `${effectiveDialCode}${selectedCountry?.code ? ` ${selectedCountry.code}` : ''}`
                                : 'Code'}
                        </span>
                        <ChevronDown className="size-3.5 shrink-0 opacity-60" />
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    data-slot="phone-country-popover"
                    className="w-[min(100vw-2rem,16rem)] p-0"
                    align="start"
                >
                    <Command>
                        <CommandInput placeholder="Search country..." />
                        <CommandList>
                            <CommandEmpty>No country found.</CommandEmpty>
                            <CommandItem
                                value="code none"
                                onSelect={() => {
                                    onChange(
                                        combinePhoneWithDialCode(
                                            '',
                                            nationalNumber,
                                        ),
                                    );
                                    handleCountryOpenChange(false);
                                }}
                            >
                                Code
                            </CommandItem>
                            {dialCountries.map((country) => (
                                <CommandItem
                                    key={country.id}
                                    value={`${country.dial_code} ${country.code} ${country.name}`}
                                    onSelect={() => {
                                        onChange(
                                            combinePhoneWithDialCode(
                                                country.dial_code!,
                                                nationalNumber,
                                            ),
                                        );
                                        handleCountryOpenChange(false);
                                    }}
                                >
                                    {country.dial_code} {country.code}
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            <Input
                id={id}
                type="tel"
                inputMode="tel"
                autoFocus={autoFocus}
                disabled={disabled}
                placeholder={nationalPlaceholder}
                className={cn(
                    'h-10 min-w-0 flex-1 rounded-xl border-input bg-background/80 dark:border-white/5 dark:bg-white/5',
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
