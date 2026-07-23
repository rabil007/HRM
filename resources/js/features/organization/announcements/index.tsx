import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import type { PaginationMeta } from '@/types/pagination';
import type { AnnouncementCan, AnnouncementListItem } from './types';

export function AnnouncementsIndexContent({
    announcements,
    pagination,
    search: initialSearch,
    filters: initialFilters,
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
    const list = useServerPaginationFilters({
        url: '/organization/announcements',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });

    return (
        <>
            <Head title="Announcements" />
            <Main>
                <PageHeader
                    title="Announcements"
                    description="Send company-wide messages via in-app, email, and WhatsApp."
                    right={
                        can.create ? (
                            <Button asChild>
                                <Link href="/organization/announcements/create">
                                    <Plus className="size-4" />
                                    Create announcement
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
                    <SearchBar
                        value={list.searchInput}
                        onChange={list.onSearchChange}
                        placeholder="Search announcements..."
                        className="mb-0 flex-1"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Select
                            value={initialFilters.status || 'all'}
                            onValueChange={(value) =>
                                list.applyFilters({
                                    ...initialFilters,
                                    status: value === 'all' ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {filterOptions.statuses.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={initialFilters.category || 'all'}
                            onValueChange={(value) =>
                                list.applyFilters({
                                    ...initialFilters,
                                    category: value === 'all' ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="Category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All categories
                                </SelectItem>
                                {filterOptions.categories.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={initialFilters.priority || 'all'}
                            onValueChange={(value) =>
                                list.applyFilters({
                                    ...initialFilters,
                                    priority: value === 'all' ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="Priority" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All priorities
                                </SelectItem>
                                {filterOptions.priorities.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Title</TableHead>
                                <TableHead>Audience</TableHead>
                                <TableHead>Channels</TableHead>
                                <TableHead>Priority</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Scheduled/Published</TableHead>
                                <TableHead>Created By</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {announcements.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        No announcements found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                announcements.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">
                                            {item.title}
                                        </TableCell>
                                        <TableCell>
                                            {item.audience_summary}
                                        </TableCell>
                                        <TableCell>
                                            {item.channels.join(', ')}
                                        </TableCell>
                                        <TableCell>
                                            {item.priority_label}
                                        </TableCell>
                                        <TableCell>
                                            {item.status_label}
                                        </TableCell>
                                        <TableCell>
                                            {item.published_at ??
                                                item.scheduled_at ??
                                                '—'}
                                        </TableCell>
                                        <TableCell>
                                            {item.created_by ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/organization/announcements/${item.id}`}
                                                    >
                                                        View
                                                    </Link>
                                                </Button>
                                                {can.update &&
                                                (item.status === 'draft' ||
                                                    item.status ===
                                                        'scheduled') ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/organization/announcements/${item.id}/edit`}
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                                {can.publish &&
                                                item.status === 'draft' ? (
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                `/organization/announcements/${item.id}/publish`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Publish
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="mt-4">
                    <Pagination {...list.paginationProps} />
                </div>
            </Main>
        </>
    );
}
