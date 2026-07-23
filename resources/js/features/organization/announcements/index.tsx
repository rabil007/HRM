import { Head, Link, router } from '@inertiajs/react';
import {
    Bell,
    Mail,
    Megaphone,
    MessageCircle,
    Plus,
    Search,
    Send,
    Smartphone,
    Users,
} from 'lucide-react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import type { AnnouncementCan, AnnouncementListItem } from './types';

const CHANNEL_ICONS: Record<string, { Icon: React.ElementType; label: string }> = {
    in_app: { Icon: Smartphone, label: 'In-app' },
    email: { Icon: Mail, label: 'Email' },
    whatsapp: { Icon: MessageCircle, label: 'WhatsApp' },
};

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

    const activeFilters = Object.values(initialFilters).filter(Boolean).length;

    const statusVariant = (
        status: string,
    ): 'success' | 'info' | 'destructive' | 'secondary' => {
        if (status === 'published') {
return 'success';
}

        if (status === 'scheduled') {
return 'info';
}

        if (status === 'cancelled' || status === 'failed') {
return 'destructive';
}

        return 'secondary';
    };

    const priorityVariant = (priority: string): 'warning' | 'outline' => {
        if (priority === 'urgent' || priority === 'high') {
return 'warning';
}

        return 'outline';
    };

    const priorityDot = (priority: string) => {
        if (priority === 'urgent') {
            return 'bg-destructive animate-pulse';
        }

        if (priority === 'high') {
            return 'bg-warning';
        }

        return 'bg-muted-foreground/40';
    };

    return (
        <>
            <Head title="Announcements" />
            <Main>
                <PageHeader
                    title="Announcements"
                    description="Send company-wide messages via in-app, email, and WhatsApp."
                    kicker="Communications"
                    right={
                        can.create ? (
                            <Button asChild>
                                <Link href="/organization/announcements/create">
                                    <Plus className="size-4" />
                                    New announcement
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                {/* Filter bar */}
                <div className="mb-6 rounded-2xl border border-border/70 bg-card/60 p-3 shadow-sm backdrop-blur-xl">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                        <SearchBar
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                            placeholder="Search by title or audience..."
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
                                <SelectTrigger className="w-[150px]">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All statuses</SelectItem>
                                    {filterOptions.statuses.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
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
                                <SelectTrigger className="w-[150px]">
                                    <SelectValue placeholder="Category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All categories</SelectItem>
                                    {filterOptions.categories.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
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
                                <SelectTrigger className="w-[150px]">
                                    <SelectValue placeholder="Priority" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All priorities</SelectItem>
                                    {filterOptions.priorities.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {activeFilters > 0 ? (
                        <div className="mt-3 flex items-center gap-2 px-2 text-xs text-muted-foreground">
                            <Search className="size-3.5" />
                            {activeFilters} active filter
                            {activeFilters === 1 ? '' : 's'}
                            <button
                                className="ml-1 font-medium text-primary hover:underline"
                                onClick={() =>
                                    list.applyFilters({
                                        status: '',
                                        category: '',
                                        priority: '',
                                    })
                                }
                            >
                                Clear all
                            </button>
                        </div>
                    ) : null}
                </div>

                {announcements.length > 0 ? (
                    <OrganizationDataTable minWidth="min-w-[1000px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Announcement</DataTableHead>
                                <DataTableHead>Audience</DataTableHead>
                                <DataTableHead>Channels</DataTableHead>
                                <DataTableHead>Priority</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead>Timing</DataTableHead>
                                <DataTableHead>Created by</DataTableHead>
                                <DataTableHead className="text-right">Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {announcements.map((item) => (
                                <TableRow
                                    key={item.id}
                                    className={dataTableBodyRowClass()}
                                >
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        <div className="flex items-start gap-3">
                                            <div className="relative mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                                <Megaphone className="size-4" />
                                                {/* Priority indicator dot */}
                                                <span
                                                    className={cn(
                                                        'absolute -right-0.5 -top-0.5 size-2.5 rounded-full ring-2 ring-background',
                                                        priorityDot(item.priority),
                                                    )}
                                                />
                                            </div>
                                            <div className="min-w-0">
                                                <Link
                                                    className="line-clamp-2 font-medium hover:text-primary"
                                                    href={`/organization/announcements/${item.id}`}
                                                >
                                                    {item.title}
                                                </Link>
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    {item.category_label}
                                                </span>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="flex items-center gap-2 text-sm">
                                            <Users className="size-3.5 shrink-0 text-muted-foreground" />
                                            <span className="line-clamp-1">{item.audience_summary}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <TooltipProvider delayDuration={200}>
                                            <div className="flex gap-1.5">
                                                {item.channels.map((channel) => {
                                                    const meta = CHANNEL_ICONS[channel];

                                                    if (!meta) {
                                                        return (
                                                            <Badge key={channel} variant="outline" className="capitalize">
                                                                {channel.replace('_', ' ')}
                                                            </Badge>
                                                        );
                                                    }

                                                    const { Icon, label } = meta;

                                                    return (
                                                        <Tooltip key={channel}>
                                                            <TooltipTrigger asChild>
                                                                <div className="flex size-7 items-center justify-center rounded-lg bg-muted/60 text-muted-foreground hover:bg-primary/10 hover:text-primary transition-colors">
                                                                    <Icon className="size-3.5" />
                                                                </div>
                                                            </TooltipTrigger>
                                                            <TooltipContent>{label}</TooltipContent>
                                                        </Tooltip>
                                                    );
                                                })}
                                            </div>
                                        </TooltipProvider>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <Badge variant={priorityVariant(item.priority)}>
                                            {item.priority_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <Badge variant={statusVariant(item.status)}>
                                            {item.status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell
                                        className={cn(dataTableCellClass(), 'whitespace-nowrap')}
                                    >
                                        <div className="flex items-center gap-2 text-sm">
                                            <Bell className="size-3.5 text-muted-foreground" />
                                            {item.published_at ?? item.scheduled_at ?? (
                                                <span className="text-muted-foreground/60 italic">Not scheduled</span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <span className="text-sm">{item.created_by ?? '—'}</span>
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={`/organization/announcements/${item.id}`}>
                                                    View
                                                </Link>
                                            </Button>
                                            {can.update &&
                                            (item.status === 'draft' ||
                                                item.status === 'scheduled') ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link href={`/organization/announcements/${item.id}/edit`}>
                                                        Edit
                                                    </Link>
                                                </Button>
                                            ) : null}
                                            {can.publish && item.status === 'draft' ? (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/organization/announcements/${item.id}/publish`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Publish
                                                </Button>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>
                ) : null}

                {announcements.length === 0 ? (
                    <EmptyState
                        icon={
                            <div className="mx-auto mb-4 flex size-16 items-center justify-center rounded-2xl bg-primary/10">
                                <Send className="size-7 text-primary" />
                            </div>
                        }
                        title={
                            activeFilters > 0
                                ? 'No matching announcements'
                                : 'No announcements yet'
                        }
                        description={
                            activeFilters > 0
                                ? 'Try adjusting your search or filters.'
                                : 'Create your first announcement to keep employees informed.'
                        }
                        action={
                            can.create ? (
                                <Button asChild>
                                    <Link href="/organization/announcements/create">
                                        <Plus className="size-4" /> Create announcement
                                    </Link>
                                </Button>
                            ) : null
                        }
                    />
                ) : null}

                <div className="mt-4">
                    <Pagination {...list.paginationProps} />
                </div>
            </Main>
        </>
    );
}
