import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type { CrewMovementCorrectionSlaStatus } from '../types';

const VARIANTS: Record<
    CrewMovementCorrectionSlaStatus,
    'secondary' | 'warning' | 'destructive' | 'outline'
> = {
    normal: 'secondary',
    attention: 'warning',
    overdue: 'destructive',
    not_applicable: 'outline',
};

export function CrewMovementCorrectionSlaBadge({
    status,
    label,
}: {
    status: CrewMovementCorrectionSlaStatus;
    label: string;
}): ReactElement {
    return <Badge variant={VARIANTS[status]}>{label}</Badge>;
}
