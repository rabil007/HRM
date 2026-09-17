export function resolveDestinationCheckInDateOnP2AEntry({
    redeployDate,
    currentCheckInDate,
    lastAutoCheckInDate,
    noHotelAccommodation,
}: {
    redeployDate: string;
    currentCheckInDate: string;
    lastAutoCheckInDate: string;
    noHotelAccommodation: boolean;
}): { checkInDate: string; lastAutoCheckInDate: string } | null {
    if (noHotelAccommodation || redeployDate === '') {
        return null;
    }

    if (
        currentCheckInDate === '' ||
        currentCheckInDate === lastAutoCheckInDate
    ) {
        return {
            checkInDate: redeployDate,
            lastAutoCheckInDate: redeployDate,
        };
    }

    return null;
}
