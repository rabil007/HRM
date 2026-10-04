import { Head } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { CrewReadinessWorkspace } from '@/features/organization/crew-readiness/components/crew-readiness-workspace';
import type {
    CrewReadinessPayload,
    OptionItem,
} from '@/features/organization/crew-readiness/types';

interface Props {
    readiness: CrewReadinessPayload;
    vessels: OptionItem[];
    positions: OptionItem[];
}

export default function CrewReadinessIndex({
    readiness,
    vessels,
    positions,
}: Props) {
    return (
        <>
            <Head title="Crew Readiness" />
            <Main>
                <div className="border-b px-4 pt-5 pb-4">
                    <PageHeader
                        kicker="Crew Operations"
                        title="Crew Readiness"
                        description="Track upcoming crew mobilisation and resolve readiness issues before joining."
                    />
                </div>
                <CrewReadinessWorkspace
                    readiness={readiness}
                    vessels={vessels}
                    positions={positions}
                />
            </Main>
        </>
    );
}
