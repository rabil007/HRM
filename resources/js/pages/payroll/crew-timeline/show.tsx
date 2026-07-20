import { Head } from '@inertiajs/react';
import { CrewTimelineReviewContent } from '@/features/payroll/crew-timeline/crew-timeline-review-content';
import type { CrewTimelineShowProps } from '@/features/payroll/crew-timeline/types';

export default function CrewTimelineShow(props: CrewTimelineShowProps) {
    return (
        <>
            <Head
                title={`Crew Timeline v${props.preparation.version} · ${props.period.name}`}
            />
            <CrewTimelineReviewContent {...props} />
        </>
    );
}
