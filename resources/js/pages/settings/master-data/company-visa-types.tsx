import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';
import type { PaginationMeta } from '@/types/pagination';

type CompanyVisaType = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function CompanyVisaTypes({
    company_visa_types,
    pagination,
    search = '',
}: {
    company_visa_types: CompanyVisaType[];
    pagination: PaginationMeta;
    search?: string;
}) {
    return (
        <MasterDataNameActivePage
            headTitle="Sponsors"
            title="Sponsors"
            description="Manage sponsor titles used across the system."
            resource="company-visa-types"
            baseUrl="/settings/master-data/company-visa-types"
            items={company_visa_types}
            pagination={pagination}
            search={search}
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
