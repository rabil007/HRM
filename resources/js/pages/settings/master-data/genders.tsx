import { MasterDataNameActivePage } from '@/components/settings/master-data-name-active-page';
import type { PaginationMeta } from '@/types/pagination';

type Gender = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function Genders({
    genders,
    pagination,
    search = '',
}: {
    genders: Gender[];
    pagination: PaginationMeta;
    search?: string;
}) {
    return (
        <MasterDataNameActivePage
            headTitle="Genders"
            title="Genders"
            description="Manage genders used across the system."
            resource="genders"
            baseUrl="/settings/master-data/genders"
            items={genders}
            pagination={pagination}
            search={search}
            entityLabel="gender"
            searchPlaceholder="Search genders..."
            createButtonLabel="Add gender"
            namePlaceholder="Male"
            sheetDescription="Keep names short and consistent."
            emptyLabel="No genders found."
            createSubmitLabel="Create gender"
        />
    );
}
