import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';

type ApprovalLocation = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function ApprovalLocations({
    approval_locations,
}: {
    approval_locations: ApprovalLocation[];
}) {
    return (
        <MasterDataNameActivePage
            headTitle="Approval locations"
            title="Approval locations"
            description="Manage approval location titles used across the system."
            resource="approval-locations"
            baseUrl="/settings/master-data/approval-locations"
            items={approval_locations}
            entityLabel="approval location"
            searchPlaceholder="Search approval locations..."
            createButtonLabel="Add approval location"
            nameColumnLabel="Title"
            nameFieldLabel="Title"
            nameFieldId="title"
            namePlaceholder="LZ Field"
            sheetDescription="Enter the approval location title only."
            emptyLabel="No approval locations found."
            createSubmitLabel="Create approval location"
        />
    );
}
