import type { VesselManningFormData, VesselManningItem } from './types';

export function toVesselManningFormData(
    vessel: VesselManningItem,
): VesselManningFormData {
    return {
        requirements: vessel.manning.map((line) => ({
            position_id: String(line.position_id),
            required_count: String(line.required_count),
        })),
    };
}

export function toVesselManningPayload(formData: VesselManningFormData): {
    requirements: Array<{ position_id: number; required_count: number }>;
    redirect_to?: 'show';
} {
    return {
        requirements: formData.requirements
            .filter((row) => row.position_id !== '')
            .map((row) => ({
                position_id: Number(row.position_id),
                required_count: Number(row.required_count),
            })),
    };
}
