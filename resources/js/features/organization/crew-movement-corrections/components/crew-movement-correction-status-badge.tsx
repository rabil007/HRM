import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type { CrewMovementCorrectionStatus } from '../types';

const VARIANTS: Record<
    CrewMovementCorrectionStatus,
    'warning' | 'success' | 'destructive' | 'secondary'
> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
    cancelled: 'secondary',
};

export function CrewMovementCorrectionStatusBadge({
    status,
    label,
}: {
    status: CrewMovementCorrectionStatus;
    label: string;
}): ReactElement {
    return <Badge variant={VARIANTS[status] ?? 'outline'}>{label}</Badge>;
}
