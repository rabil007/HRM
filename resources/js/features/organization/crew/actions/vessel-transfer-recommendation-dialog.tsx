import { router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import type { ActiveOnVesselAssignment } from '../types';

export type VesselTransferPrefill = {
    vessel_id?: number | null;
    rank_id?: number | null;
    client_id?: number | null;
    company_visa_type_id?: number | null;
    occurred_at?: string | null;
};

export function openTransferVessel(
    current: ActiveOnVesselAssignment,
    prefill: VesselTransferPrefill = {},
): void {
    const query: Record<string, string> = {
        action: 'transfer_vessel',
    };

    if (prefill.vessel_id) {
        query.vessel_id = String(prefill.vessel_id);
    }

    if (prefill.rank_id) {
        query.rank_id = String(prefill.rank_id);
    }

    if (prefill.client_id) {
        query.client_id = String(prefill.client_id);
    }

    if (prefill.company_visa_type_id) {
        query.company_visa_type_id = String(prefill.company_visa_type_id);
    }

    if (prefill.occurred_at) {
        query.occurred_at = prefill.occurred_at;
    }

    router.visit(
        showAssignment.url(current.assignment_id, {
            query,
        }),
    );
}

export function VesselTransferRecommendationDialog({
    open,
    onOpenChange,
    current,
    destinationVesselName,
    prefill,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    current: ActiveOnVesselAssignment | null;
    destinationVesselName?: string | null;
    prefill?: VesselTransferPrefill;
}): ReactElement {
    const vesselName = current?.vessel_name ?? 'the current vessel';
    const employeeName = current?.employee_name ?? 'This employee';
    const destination = destinationVesselName?.trim() || 'the selected vessel';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Possible Vessel Transfer</DialogTitle>
                    <DialogDescription>
                        {employeeName} is currently On Vessel on {vesselName}.
                    </DialogDescription>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    If {employeeName} is moving directly from {vesselName} to{' '}
                    {destination}, use Transfer Vessel instead. Transfer Vessel
                    will close the current vessel assignment and create the
                    linked destination assignment at the same movement time.
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    {current?.can_transfer !== false ? (
                        <Button
                            type="button"
                            onClick={() => {
                                if (current) {
                                    openTransferVessel(current, prefill);
                                }
                            }}
                        >
                            Use Transfer Vessel
                        </Button>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
