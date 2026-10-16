/**
 * Same-date hotel check-in/out entry for crew movement forms.
 *
 * The checkbox is the source of truth. A hotel date that happens to equal the
 * movement calendar date is not treated as linked unless this flag is on.
 * One functional form update changes the movement timestamp and any linked
 * hotel dates together so a second setData cannot drop other fields.
 */

export type HotelDateFieldKey =
    | 'check_in_date'
    | 'check_out_date'
    | 'source_check_out_date';

export type HotelDateSyncFlagKey =
    | 'check_in_date_auto_synced'
    | 'check_out_date_auto_synced'
    | 'source_check_out_date_auto_synced';

export type LinkedHotelDateBinding = {
    dateKey: HotelDateFieldKey;
    syncedKey: HotelDateSyncFlagKey;
    active: boolean;
};

export type HotelDateLinkState = {
    occurred_at: string;
    check_in_date?: string;
    check_out_date?: string;
    source_check_out_date?: string;
    check_in_date_auto_synced?: boolean;
    check_out_date_auto_synced?: boolean;
    source_check_out_date_auto_synced?: boolean;
};

export function movementCalendarDate(occurredAt: string): string {
    return occurredAt.slice(0, 10);
}

export function isHotelDateAutoSynced(value: boolean | undefined): boolean {
    return value === true;
}

/**
 * New movement entries start linked. Stored schedules without the flag stay
 * manual so rescheduling does not infer a link from equal dates.
 */
export function storedHotelDateAutoSynced(value: unknown): boolean {
    return value === true;
}

export function applyLinkedHotelDatesOnMovementChange<
    T extends HotelDateLinkState,
>(data: T, occurredAt: string, links: readonly LinkedHotelDateBinding[]): T {
    const next: T = { ...data, occurred_at: occurredAt };
    const calendarDate = movementCalendarDate(occurredAt);

    if (calendarDate === '') {
        return next;
    }

    for (const link of links) {
        if (!link.active || !isHotelDateAutoSynced(data[link.syncedKey])) {
            continue;
        }

        next[link.dateKey] = calendarDate;
    }

    return next;
}

export function setHotelDateLink<T extends HotelDateLinkState>(
    data: T,
    dateKey: HotelDateFieldKey,
    syncedKey: HotelDateSyncFlagKey,
    linked: boolean,
): T {
    if (!linked) {
        return {
            ...data,
            [syncedKey]: false,
        };
    }

    return {
        ...data,
        [dateKey]: movementCalendarDate(data.occurred_at),
        [syncedKey]: true,
    };
}

export function setManualHotelDate<T extends HotelDateLinkState>(
    data: T,
    dateKey: HotelDateFieldKey,
    syncedKey: HotelDateSyncFlagKey,
    value: string,
): T {
    return {
        ...data,
        [dateKey]: value,
        [syncedKey]: false,
    };
}
