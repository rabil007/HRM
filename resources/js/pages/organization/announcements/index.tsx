import { AnnouncementsIndexContent } from '@/features/organization/announcements';
import type {
    AnnouncementCan,
    AnnouncementListItem,
} from '@/features/organization/announcements/types';
import type { PaginationMeta } from '@/types/pagination';

export default function AnnouncementsIndex({
    announcements,
    pagination,
    search,
    filters,
    filterOptions,
    can,
}: {
    announcements: AnnouncementListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: { status: string; category: string; priority: string };
    filterOptions: {
        statuses: { value: string; label: string }[];
        categories: { value: string; label: string }[];
        priorities: { value: string; label: string }[];
    };
    can: AnnouncementCan;
}) {
    return (
        <AnnouncementsIndexContent
            announcements={announcements}
            pagination={pagination}
            search={search}
            filters={filters}
            filterOptions={filterOptions}
            can={can}
        />
    );
}
