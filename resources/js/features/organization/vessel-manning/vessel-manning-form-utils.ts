import type { VesselManningFormData, VesselManningItem } from './types';

export function toVesselManningFormData(vessel: VesselManningItem): VesselManningFormData {
    return {
        requirements: vessel.manning.map((line) => ({
            rank_id: String(line.rank_id),
            required_count: String(line.required_count),
        })),
    };
}

export function toVesselManningPayload(formData: VesselManningFormData): {
    requirements: Array<{ rank_id: number; required_count: number }>;
    redirect_to?: 'show';
} {
    return {
        requirements: formData.requirements
            .filter((row) => row.rank_id !== '')
            .map((row) => ({
                rank_id: Number(row.rank_id),
                required_count: Number(row.required_count),
            })),
    };
}
