import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CrewScheduledMovementCard } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { index as scheduledIndex } from '@/routes/organization/crew-scheduled-movements';

type Props = {
    items: CrewScheduledMovementCard[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        status: string | null;
        movement_action: string | null;
        employee_id: number | null;
        vessel_id: number | null;
        search: string | null;
        scheduled_from: string | null;
        scheduled_to: string | null;
        tab: string | null;
    };
    counts: {
        upcoming: number;
        due: number;
        needs_attention: number;
        executed: number;
        cancelled: number;
    };
    can: {
        view: boolean;
        schedule: boolean;
        manage: boolean;
    };
};

const TABS = [
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'due', label: 'Due' },
    { key: 'needs_attention', label: 'Needs Attention' },
    { key: 'executed', label: 'Executed' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'all', label: 'All' },
] as const;

export default function CrewScheduledMovementsIndex({
    items,
    pagination,
    filters,
    counts,
}: Props): ReactElement {
    const [search, setSearch] = useState(filters.search ?? '');
    const activeTab = filters.tab ?? 'upcoming';

    const visit = (
        query: Record<string, string | number | undefined>,
    ): void => {
        router.get(
            scheduledIndex.url({
                query: {
                    tab: activeTab,
                    search: search || undefined,
                    ...query,
                },
            }),
            {},
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Scheduled Crew Movements" />
            <Main>
                <PageHeader
                    title="Scheduled Crew Movements"
                    description="Future intended operational movements on existing assignments. Automatic execution only — no manual confirmation mode."
                />

                <div className="mt-4 flex flex-wrap gap-2">
                    {TABS.map((tab) => {
                        const count =
                            tab.key === 'all'
                                ? undefined
                                : counts[tab.key as keyof typeof counts];

                        return (
                            <Button
                                key={tab.key}
                                type="button"
                                size="sm"
                                variant={
                                    activeTab === tab.key
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => visit({ tab: tab.key })}
                            >
                                {tab.label}
                                {typeof count === 'number' ? (
                                    <span className="ml-1.5 opacity-80">
                                        ({count})
                                    </span>
                                ) : null}
                            </Button>
                        );
                    })}
                </div>

                <div className="mt-4">
                    <SearchBar
                        value={search}
                        onChange={setSearch}
                        placeholder="Search employee or assignment…"
                        className="mb-0"
                        right={
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    visit({ search: search || undefined })
                                }
                            >
                                Search
                            </Button>
                        }
                    />
                </div>

                <Card className="mt-4 border-border/80 dark:border-white/10">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">
                            {TABS.find((tab) => tab.key === activeTab)?.label ??
                                'Schedules'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {items.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No scheduled movements in this view.
                            </p>
                        ) : (
                            items.map((item) => (
                                <div
                                    key={item.id}
                                    className={cn(
                                        'rounded-lg border border-border/60 p-3 text-sm',
                                        item.status === 'needs_attention' &&
                                            'border-amber-500/40 bg-amber-500/5',
                                    )}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="space-y-1">
                                            <div className="font-medium">
                                                {item.movement_action_label}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {[
                                                    item.employee_name,
                                                    item.employee_no,
                                                    item.assignment_no,
                                                    item.vessel_name,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                            <div>
                                                {item.scheduled_at_display ??
                                                    item.scheduled_at}{' '}
                                                · {item.status_label}
                                            </div>
                                            {item.last_error_message ? (
                                                <div className="text-xs text-amber-800 dark:text-amber-100">
                                                    {item.last_error_message}
                                                </div>
                                            ) : null}
                                        </div>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={showAssignment.url(
                                                    item.crew_assignment_id,
                                                )}
                                            >
                                                Open assignment
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                {pagination.total > 0 ? (
                    <div className="mt-4">
                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            from={
                                pagination.total === 0
                                    ? null
                                    : (pagination.current_page - 1) *
                                          pagination.per_page +
                                      1
                            }
                            to={Math.min(
                                pagination.current_page * pagination.per_page,
                                pagination.total,
                            )}
                            total={pagination.total}
                            perPage={pagination.per_page}
                            onPageChange={(page) => visit({ page })}
                        />
                    </div>
                ) : null}
            </Main>
        </>
    );
}
