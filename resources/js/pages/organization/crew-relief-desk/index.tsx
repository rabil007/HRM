import { Head } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { ReliefDesk } from '@/features/organization/crew-planning/components/relief-desk';
import type {
    PlanningOption,
    ReliefDeskPayload,
} from '@/features/organization/crew-planning/types';

interface Props {
    relief_desk: ReliefDeskPayload;
    vessels: PlanningOption[];
    positions: PlanningOption[];
}

export default function CrewReliefDeskIndex({
    relief_desk: reliefDesk,
    vessels,
    positions,
}: Props) {
    return (
        <>
            <Head title="Relief Desk" />
            <Main>
                <div className="border-b px-4 pt-5 pb-4">
                    <PageHeader
                        kicker="Crew Operations"
                        title="Relief Desk"
                        description="Who is signing off soon, who is replacing them, and whether that relief is ready."
                    />
                </div>
                <ReliefDesk
                    desk={reliefDesk}
                    vessels={vessels}
                    positions={positions}
                />
            </Main>
        </>
    );
}
