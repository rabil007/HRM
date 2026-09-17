import type { EmployeeOperationalStatus } from '../types';

type CurrentVessel = {
    vessel_id: number | null;
    can_transfer?: boolean;
};

/**
 * A different destination vessel has been selected on the Start form.
 */
export function hasSelectedTransferDestination(
    current: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    if (
        current === null ||
        destinationVesselId === null ||
        destinationVesselId < 1
    ) {
        return false;
    }

    return (
        current.vessel_id === null || current.vessel_id !== destinationVesselId
    );
}

/** @deprecated Use hasSelectedTransferDestination */
export function recommendsVesselTransfer(
    current: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    return hasSelectedTransferDestination(current, destinationVesselId);
}

/**
 * Transfer is permitted for an employee already active P4 On Vessel.
 * Destination selection only affects labelling/prefill — not availability.
 */
export function canTransferFromP4(
    currentOnVessel: CurrentVessel | null,
    performMovement: boolean,
): boolean {
    return performMovement && currentOnVessel?.can_transfer === true;
}

/**
 * Manual Start may open the Transfer Vessel recommendation when the employee
 * is already On Vessel on a different vessel. Planning handoff must never use
 * that path because the planning row is not carried into the transfer flow.
 */
export function canUseManualTransferRecommendation(
    fromPlanning: boolean,
    bulkMode: boolean,
    currentOnVessel: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    if (fromPlanning || bulkMode) {
        return false;
    }

    return (
        hasSelectedTransferDestination(currentOnVessel, destinationVesselId) &&
        currentOnVessel?.can_transfer === true
    );
}

export function hasPlanningStartActiveAssignmentConflict(
    fromPlanning: boolean,
    bulkMode: boolean,
    employeeStatus: EmployeeOperationalStatus | null | undefined,
    canUseRecommendedTransfer: boolean,
): boolean {
    if (bulkMode || !employeeStatus?.has_active_assignment) {
        return false;
    }

    if (fromPlanning) {
        return true;
    }

    return !canUseRecommendedTransfer;
}

export function shouldShowPlanningTransferGuidance(
    employeeStatus: EmployeeOperationalStatus | null | undefined,
    currentOnVessel: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    return (
        employeeStatus?.status === 'on_vessel' &&
        hasSelectedTransferDestination(currentOnVessel, destinationVesselId)
    );
}
