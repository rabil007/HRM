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
    joining: 'outline',
    joined: 'default',
    rejected: 'destructive',
};

export function CandidateStageBadge({
    stage,
    label,
}: {
    stage: CandidateStage;
    label: string;
}) {
    return (
        <Badge
            variant={stageVariant[stage] ?? 'secondary'}
            className={
                stage === 'joined'
                    ? 'bg-emerald-600 text-white hover:bg-emerald-600'
                    : undefined
            }
        >
            {label}
        </Badge>
    );
}

export function CandidateJoiningScheduleBadge({
    urgency,
    label,
}: {
    urgency: 'overdue' | 'today' | 'upcoming' | null | undefined;
    label: string | null | undefined;
}) {
    if (!urgency || !label) {
        return null;
    }

    if (urgency === 'overdue') {
        return (
            <Badge variant="destructive" className="font-medium">
                {label}
            </Badge>
        );
    }

    if (urgency === 'today') {
        return (
            <Badge
                variant="default"
                className="bg-amber-600 font-medium text-white hover:bg-amber-600"
            >
                {label}
            </Badge>
        );
    }

    return (
        <Badge variant="outline" className="text-muted-foreground">
            {label}
        </Badge>
    );
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
