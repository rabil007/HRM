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
    const destination = destinationVesselName?.trim() || 'another vessel';
    const startedAt = current?.actual_start_display
        ? ` P4 On Vessel started ${current.actual_start_display}.`
        : '';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Possible Vessel Transfer</DialogTitle>
                    <DialogDescription>
                        {employeeName} is already On Vessel on {vesselName}.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2 text-sm text-muted-foreground">
                    <p>
                        Current assignment {current?.assignment_no ?? '—'} is
                        active P4 On Vessel on {vesselName}.{startedAt}
                    </p>
                    <p>
                        Creating or joining another vessel assignment may
                        produce conflicting operational history. If{' '}
                        {employeeName} is moving directly from {vesselName} to{' '}
                        {destination}, Transfer Vessel is the recommended
                        action. It will close the current assignment and start
                        the linked destination at the same movement time. You
                        still review and submit that movement; nothing is
                        transferred automatically.
                    </p>
                </div>
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
