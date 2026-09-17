import type { CurrentCrewHomeAvailabilityStatus } from '../types';

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
