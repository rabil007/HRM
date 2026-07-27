import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';

type SssaOption = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function SssaOptions({
    sssa_options,
}: {
    sssa_options: SssaOption[];
}) {
    return (
        <MasterDataNameActivePage
            headTitle="SSSA options"
            title="SSSA options"
            description="Manage SSSA option titles used across the system."
            resource="sssa-options"
            baseUrl="/settings/master-data/sssa-options"
            items={sssa_options}
            entityLabel="SSSA option"
            searchPlaceholder="Search SSSA options..."
            createButtonLabel="Add SSSA option"
            nameColumnLabel="Title"
            nameFieldLabel="Title"
            nameFieldId="title"
            namePlaceholder="Supply"
            sheetDescription="Enter the SSSA option title only."
            emptyLabel="No SSSA options found."
            createSubmitLabel="Create SSSA option"
        />
    );
}
