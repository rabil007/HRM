/**
 * Single-create arrival field presentation (direct footer actions).
 * Backend still distinguishes planned vs actual on submit via already_arrived.
 */

export const SINGLE_CREATE_ALREADY_ARRIVAL_FOOTER_NOTE =
    'Required when you choose Already Arrived in the footer.';

export function shouldShowSingleCreatePreJoinAccommodation(options: {
    useDirectCreateActions: boolean;
    canStart: boolean;
    bulkMode: boolean;
}): boolean {
    return (
        options.useDirectCreateActions && options.canStart && !options.bulkMode
    );
}

export function resolveCrewMemberArrivalPresentation(options: {
    bulkAlreadyArrived: boolean;
    showSingleCreatePreJoin: boolean;
}): {
    useActualArrivalLabel: boolean;
    showPreJoinAccommodation: boolean;
    showAlreadyArrivedFooterNote: boolean;
} {
    if (options.bulkAlreadyArrived) {
        return {
            useActualArrivalLabel: true,
            showPreJoinAccommodation: true,
            showAlreadyArrivedFooterNote: false,
        };
    }

    if (options.showSingleCreatePreJoin) {
        return {
            useActualArrivalLabel: false,
            showPreJoinAccommodation: true,
            showAlreadyArrivedFooterNote: true,
        };
    }

    return {
        useActualArrivalLabel: false,
        showPreJoinAccommodation: false,
        showAlreadyArrivedFooterNote: false,
    };
}
