import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type { CrewMovementCorrectionAgeStatus } from '../types';

const VARIANTS: Record<
    CrewMovementCorrectionAgeStatus,
    'secondary' | 'warning' | 'destructive' | 'outline'
> = {
    on_time: 'secondary',
    needs_attention: 'warning',
    overdue: 'destructive',
    not_applicable: 'outline',
};

export function CrewMovementCorrectionAgeBadge({
    status,
    label,
}: {
    status: CrewMovementCorrectionAgeStatus;
    label: string;
}): ReactElement {
    return <Badge variant={VARIANTS[status]}>{label}</Badge>;
}
