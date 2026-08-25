/** Matches `md` and `useIsMobile` (max-width: 767px). */
export const MOBILE_OPERATIONAL_LIST_BREAKPOINT = 768;

export const MOBILE_OPERATIONAL_LIST_CLASS = 'md:hidden';
export const DESKTOP_OPERATIONAL_TABLE_CLASS = 'hidden md:block';

export function shouldUseMobileOperationalList(viewportWidth: number): boolean {
    return viewportWidth < MOBILE_OPERATIONAL_LIST_BREAKPOINT;
}

export function joinMobileRecordMeta(
    parts: Array<string | null | undefined>,
): string {
    return parts
        .map((part) => part?.trim())
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}

export function isExternalMobileOverflowHref(action: {
    href?: string;
    target?: string;
}): boolean {
    if (action.href === undefined || action.href.trim() === '') {
        return false;
    }

    if (
        action.target !== undefined &&
        action.target !== '' &&
        action.target !== '_self'
    ) {
        return true;
    }

    return /^(https?:|mailto:|tel:)/i.test(action.href);
}
