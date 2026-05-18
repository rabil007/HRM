export type PhoneCountryOption = {
    id: number;
    name: string;
    code: string;
    dial_code: string | null;
};

export function countriesWithDialCode(
    countries: PhoneCountryOption[],
): PhoneCountryOption[] {
    return countries.filter((country) => Boolean(country.dial_code?.trim()));
}

export function parsePhoneWithDialCode(
    full: string | null | undefined,
    countries: PhoneCountryOption[],
): { dialCode: string; nationalNumber: string } {
    const trimmed = (full ?? '').trim();

    if (!trimmed) {
        return { dialCode: '', nationalNumber: '' };
    }

    const normalized = trimmed.startsWith('+')
        ? trimmed
        : `+${trimmed.replace(/\D/g, '')}`;

    const sorted = countriesWithDialCode(countries).sort(
        (a, b) => (b.dial_code?.length ?? 0) - (a.dial_code?.length ?? 0),
    );

    for (const country of sorted) {
        const dialCode = country.dial_code!;

        if (normalized.startsWith(dialCode)) {
            return {
                dialCode,
                nationalNumber: normalized
                    .slice(dialCode.length)
                    .replace(/\D/g, ''),
            };
        }
    }

    return {
        dialCode: '',
        nationalNumber: trimmed.replace(/\D/g, ''),
    };
}

export function combinePhoneWithDialCode(
    dialCode: string,
    nationalNumber: string,
): string {
    const digits = nationalNumber.replace(/\D/g, '');
    const dial = dialCode.trim();

    if (!digits && !dial) {
        return '';
    }

    if (!dial) {
        return digits;
    }

    return `${dial}${digits}`;
}

export function formatPhoneForDisplay(
    full: string | null | undefined,
): string {
    const trimmed = (full ?? '').trim();

    return trimmed === '' ? '—' : trimmed;
}

export function defaultDialCodeForPhoneField(
    fieldKey: string,
    countries: PhoneCountryOption[],
): string {
    const uae = countries.find((country) => country.code === 'UAE')?.dial_code;

    if (fieldKey === 'phone' || fieldKey === 'emergency_phone') {
        return uae ?? '+971';
    }

    return countriesWithDialCode(countries)[0]?.dial_code ?? '';
}
