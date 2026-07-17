import { Ban, Check, Eye, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { TableRowActions } from '@/components/table-row-actions';
import type { TableRowActionItem } from '@/components/table-row-actions';
import { show as showCorrection } from '@/routes/organization/crew-movement-corrections';
import type { CrewMovementCorrectionListItem } from '../types';

export function CrewMovementCorrectionRowActions({
    correction,
    canApprove,
    canCancel,
    onApprove,
    onReject,
    onCancel,
}: {
    correction: CrewMovementCorrectionListItem;
    canApprove: boolean;
    canCancel: boolean;
    onApprove: (correction: CrewMovementCorrectionListItem) => void;
    onReject: (correction: CrewMovementCorrectionListItem) => void;
    onCancel: (correction: CrewMovementCorrectionListItem) => void;
}): ReactElement {
    const isPending = correction.status === 'pending';

    const actions: TableRowActionItem[] = [
        {
            label: 'View',
            icon: Eye,
            href: showCorrection.url(correction.id),
        },
        {
            label: 'Approve',
            icon: Check,
            variant: 'success',
            onClick: () => onApprove(correction),
            hidden: !(isPending && canApprove),
        },
        {
            label: 'Reject',
            icon: X,
            variant: 'danger',
            onClick: () => onReject(correction),
            hidden: !(isPending && canApprove),
        },
        {
            label: 'Cancel',
            icon: Ban,
            onClick: () => onCancel(correction),
            hidden: !(isPending && canCancel),
        },
    ];

    return <TableRowActions actions={actions} />;
}
