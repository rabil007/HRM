import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';

type VisaType = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function VisaTypes({ visa_types }: { visa_types: VisaType[] }) {
    return (
        <MasterDataNameActivePage
            headTitle="Visa types"
            title="Visa types"
            description="Manage visa type titles used across the system."
            resource="visa-types"
            baseUrl="/settings/master-data/visa-types"
            items={visa_types}
            entityLabel="visa type"
            searchPlaceholder="Search visa types..."
            createButtonLabel="Add visa type"
            nameColumnLabel="Title"
            nameFieldLabel="Title"
            nameFieldId="title"
            namePlaceholder="Residential Visa"
            sheetDescription="Enter the visa type title only."
            emptyLabel="No visa types found."
            createSubmitLabel="Create visa type"
        />
    );
}
