import { Head } from '@inertiajs/react';
import { CrewRankPoliciesContent } from '@/features/organization/crew-operations/rank-policies';
import type {
    CrewRankPolicyItem,
    CrewRankPolicyPagePermissions,
} from '@/features/organization/crew-operations/rank-policies/types';

export default function CrewRankPoliciesPage({
    policies,
    can,
}: {
    policies: CrewRankPolicyItem[];
    can: CrewRankPolicyPagePermissions;
}) {
    return (
        <>
            <Head title="Rank Tour Policies" />
            <CrewRankPoliciesContent policies={policies} can={can} />
        </>
    );
}
