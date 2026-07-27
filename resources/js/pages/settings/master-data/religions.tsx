import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';

type Religion = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function Religions({ religions }: { religions: Religion[] }) {
    return (
        <MasterDataNameActivePage
            headTitle="Religions"
            title="Religions"
            description="Manage religions used across the system."
            resource="religions"
            baseUrl="/settings/master-data/religions"
            items={religions}
            entityLabel="religion"
            searchPlaceholder="Search religions..."
            createButtonLabel="Add religion"
            namePlaceholder="Muslim"
            sheetDescription="Keep names short and consistent."
            emptyLabel="No religions found."
            createSubmitLabel="Create religion"
        />
    );
}
