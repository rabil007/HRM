import type {
    ActiveOnVesselAssignment,
    CurrentCrewHomeAvailabilityStatus,
    EmployeeOperationalStatus,
} from '../types';
import { recommendsVesselTransfer } from './vessel-transfer-recommendation.ts';

export type AssignmentReadinessAttentionItem = {
    id: string;
    title: string;
    description: string;
    tone: 'warning' | 'info';
};

export type AssignmentReadinessRecommendation = {
    title: string;
    description: string;
    tone: 'positive' | 'neutral' | 'warning';
};

export function buildAssignmentReadinessAttentionItems(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
    destinationVesselId: number | null,
): AssignmentReadinessAttentionItem[] {
    if (!status) {
        return [];
    }

    const items: AssignmentReadinessAttentionItem[] = [];

    if (status.has_active_assignment) {
        const vesselName =
            status.vessel_name ??
            status.current_vessel ??
            activeOnVessel?.vessel_name ??
            'their current vessel';

        items.push({
            id: 'existing-assignment',
            title: 'Existing assignment',
            description: status.assignment_no
                ? `This employee is currently assigned to ${vesselName} under ${status.assignment_no}.`
                : 'This employee already has an active Crew Assignment.',
            tone: 'warning',
        });

        if (
            status.status !== 'on_vessel' ||
            !recommendsVesselTransfer(activeOnVessel, destinationVesselId)
        ) {
            items.push({
                id: 'potential-conflict',
                title: 'Potential assignment conflict',
                description:
                    'The employee already has an active assignment. Review the current assignment before creating another.',
                tone: 'warning',
            });
        }
    }

    if (status.status === 'on_vessel') {
        items.push({
            id: 'on-vessel',
            title: 'Already On Vessel',
            description:
                'This employee currently has an active vessel assignment.',
            tone: 'warning',
        });
    }

    if (status.status === 'join_standby') {
        items.push({
            id: 'join-standby',
            title: 'Join Standby',
            description:
                'The employee is currently waiting in Join Standby before vessel joining.',
            tone: 'info',
        });
    }

    if (status.status === 'training') {
        items.push({
            id: 'training',
            title: 'In Training',
            description:
                'The employee is currently in training before vessel joining.',
            tone: 'info',
        });
    }

    if (status.status === 'ready_to_join') {
        items.push({
            id: 'ready-to-join',
            title: 'Ready to Join',
            description:
                'The employee is marked ready to join and may not need another assignment yet.',
            tone: 'info',
        });
    }

    if (
        status.availability_status === 'over_limit' ||
        status.availability_status === 'near_limit'
    ) {
        items.push({
            id: 'home-availability',
            title:
                status.availability_status === 'over_limit'
                    ? 'Home availability exceeded'
                    : 'Home availability nearing limit',
            description:
                status.availability_detail ??
                'Review the employee home stay before creating another assignment.',
            tone: 'warning',
        });
    }

    if (status.warning) {
        items.push({
            id: 'status-warning',
            title: 'Operational attention',
            description: status.warning,
            tone: 'warning',
        });
    }

    return items;
}

export function buildAssignmentReadinessRecommendation(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
    destinationVesselId: number | null,
): AssignmentReadinessRecommendation | null {
    if (!status) {
        return null;
    }

    if (
        status.status === 'on_vessel' &&
        recommendsVesselTransfer(activeOnVessel, destinationVesselId)
    ) {
        return {
            title: 'Review current assignment / consider Transfer Vessel',
            description:
                'If this is a direct vessel change, use Transfer Vessel instead of creating an overlapping assignment.',
            tone: 'warning',
        };
    }

    if (status.status === 'on_vessel') {
        return {
            title: 'Review current assignment',
            description:
                'Confirm the existing vessel assignment before creating another mobilisation cycle.',
            tone: 'warning',
        };
    }

    if (status.status === 'join_standby' || status.status === 'demob_standby') {
        return {
            title: 'Review standby assignment before creating another',
            description:
                'Check the current standby assignment and movement history before starting a new cycle.',
            tone: 'neutral',
        };
    }

    if (status.status === 'training') {
        return {
            title: 'Review training assignment before creating another',
            description:
                'The employee is still in an active training phase on another assignment.',
            tone: 'neutral',
        };
    }

    if (
        status.status === 'in_home' ||
        status.status === 'home_redeploy' ||
        (!status.has_active_assignment && status.status === 'in_home')
    ) {
        if (status.availability_status === 'over_limit') {
            return {
                title: 'Available for assignment',
                description:
                    'The employee is home over the availability limit, but no active assignment conflict exists.',
                tone: 'neutral',
            };
        }

        return {
            title: 'Available for assignment',
            description: 'The employee has no active assignment conflict.',
            tone: 'positive',
        };
    }

    if (!status.has_active_assignment) {
        return {
            title: 'Ready for assignment',
            description: 'The employee has no active assignment conflict.',
            tone: 'positive',
        };
    }

    return null;
}

export function shouldShowTransferVesselSuggestion(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
    destinationVesselId: number | null,
): boolean {
    return (
        status?.status === 'on_vessel' &&
        recommendsVesselTransfer(activeOnVessel, destinationVesselId)
    );
}

export function readinessStatusLabel(
    statusCode: string | null | undefined,
): string {
    switch (statusCode) {
        case 'on_vessel':
            return 'On Vessel';
        case 'join_standby':
            return 'Standby';
        case 'demob_standby':
            return 'Standby';
        case 'in_home':
            return 'Home';
        case 'home_redeploy':
            return 'Home';
        case 'training':
            return 'Training';
        case 'ready_to_join':
            return 'Ready to Join';
        case 'pre_mobilisation':
            return 'Pre-Mobilisation';
        case 'travel_in':
            return 'Travel';
        default:
            return 'Available';
    }
}

export function isHomeAvailabilityStatus(
    status: CurrentCrewHomeAvailabilityStatus | null | undefined,
): boolean {
    return (
        status === 'within_limit' ||
        status === 'near_limit' ||
        status === 'over_limit'
    );
}
