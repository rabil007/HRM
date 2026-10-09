import { Badge } from '@/components/ui/badge';
import type {
    CandidateInterviewOutcome,
    CandidateOfferStatus,
    CandidateStage,
} from '../types';

const stageVariant: Record<
    CandidateStage,
    'secondary' | 'outline' | 'default' | 'destructive'
> = {
    applied: 'secondary',
    screening: 'outline',
    interview: 'default',
    offer_jol: 'outline',
    joining: 'default',
    rejected: 'destructive',
};

export function CandidateStageBadge({
    stage,
    label,
}: {
    stage: CandidateStage;
    label: string;
}) {
    return <Badge variant={stageVariant[stage] ?? 'secondary'}>{label}</Badge>;
}

export function CandidateOutcomeBadge({
    outcome,
    label,
}: {
    outcome: CandidateInterviewOutcome;
    label: string | null;
}) {
    if (!outcome || !label) {
        return null;
    }

    return (
        <Badge
            variant={outcome === 'selected' ? 'default' : 'destructive'}
            className={
                outcome === 'selected'
                    ? 'bg-emerald-600 hover:bg-emerald-600'
                    : undefined
            }
        >
            {label}
        </Badge>
    );
}

export function CandidateOfferStatusBadge({
    status,
    label,
}: {
    status: CandidateOfferStatus | null | undefined;
    label: string | null | undefined;
}) {
    if (!status || !label) {
        return null;
    }

    return (
        <Badge
            variant={
                status === 'accepted'
                    ? 'default'
                    : status === 'rejected'
                      ? 'destructive'
                      : 'secondary'
            }
            className={
                status === 'accepted'
                    ? 'bg-emerald-600 hover:bg-emerald-600'
                    : undefined
            }
        >
            Offer: {label}
        </Badge>
    );
}
