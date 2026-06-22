import { Head } from '@inertiajs/react';
import {
    CrewOperationsDashboardContent,
    type CrewOperationsDashboardProps,
} from '@/features/organization/crew-operations';

export default function CrewOperationsOverview(props: CrewOperationsDashboardProps) {
    return (
        <>
            <Head title="Crew Operations" />
            <CrewOperationsDashboardContent {...props} />
        </>
    );
}
