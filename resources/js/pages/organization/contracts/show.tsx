import { Head } from '@inertiajs/react';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { ContractsShowContent } from '@/features/organization/contracts/contracts-show-content';
import type {
    ContractBackNavigation,
    ContractListItem,
    ContractPageCan,
} from '@/features/organization/contracts/types';

type Props = {
    contract: ContractListItem;
    can: ContractPageCan;
    back: ContractBackNavigation;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

export default function ContractShow(props: Props) {
    return (
        <>
            <Head title={`Contract — ${props.contract.employee_name}`} />
            <ContractsShowContent {...props} />
        </>
    );
}
