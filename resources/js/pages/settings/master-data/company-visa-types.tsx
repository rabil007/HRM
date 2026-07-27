import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';

type CompanyVisaType = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function CompanyVisaTypes({
    company_visa_types,
}: {
    company_visa_types: CompanyVisaType[];
}) {
    return (
        <MasterDataNameActivePage
            headTitle="Sponsors"
            title="Sponsors"
            description="Manage sponsor titles used across the system."
            resource="company-visa-types"
            baseUrl="/settings/master-data/company-visa-types"
            items={company_visa_types}
            entityLabel="sponsor"
            searchPlaceholder="Search sponsors..."
            createButtonLabel="Add sponsor"
            nameColumnLabel="Title"
            nameFieldLabel="Title"
            nameFieldId="title"
            namePlaceholder="Company Sponsored"
            sheetDescription="Enter the sponsor title only."
            emptyLabel="No sponsors found."
            createSubmitLabel="Create sponsor"
        />
    );
}
