import {
    AlertTriangle,
    CalendarClock,
    Flame,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type {
    RequirementFilters,
    RequirementIndexProps,
} from '@/types/recruitment';

type Props = Pick<
    RequirementIndexProps,
    'filters' | 'options' | 'tab_counts'
> & {
    searchInput: string;
    total: number;
    isLoading: boolean;
    onSearchChange: (value: string) => void;
    onClearSearch: () => void;
    onChange: (filters: Partial<RequirementFilters>) => void;
    onOpenFilters: () => void;
    onReset: () => void;
};

export function RequirementToolbar({
    filters,
    options,
    tab_counts,
    searchInput,
    total,
    isLoading,
    onSearchChange,
    onClearSearch,
    onChange,
    onOpenFilters,
    onReset,
}: Props) {
    const chips: {
        key: keyof RequirementFilters;
        label: string;
        value: string | number;
    }[] = [];
    const criteria = [
        {
            key: 'client_id' as const,
            label: 'Client',
            value: options.clients.find(
                (item) => String(item.id) === String(filters.client_id),
            )?.name,
        },
        {
            key: 'project_id' as const,
            label: 'Project',
            value: options.projects.find(
                (item) => String(item.id) === String(filters.project_id),
            )?.title,
        },
        {
            key: 'position_id' as const,
            label: 'Position',
            value: options.positions.find(
                (item) => String(item.id) === String(filters.position_id),
            )?.title,
        },
        {
            key: 'assigned_to' as const,
            label: 'Recruiter',
            value: options.recruiters.find(
                (item) => String(item.id) === String(filters.assigned_to),
            )?.name,
        },
        {
            key: 'priority' as const,
            label: 'Priority',
            value: filters.priority === 'urgent' ? 'Urgent' : 'Normal',
        },
        {
            key: 'deadline_health' as const,
            label: 'Deadline',
            value:
                filters.deadline_health === 'due_soon'
                    ? 'Due in 7 days'
                    : filters.deadline_health === 'overdue'
                      ? 'Overdue'
                      : 'On track',
        },
    ];

    for (const criterion of criteria) {
        const selected = filters[criterion.key];

        if (selected) {
            chips.push({
                key: criterion.key,
                label: criterion.label,
                value: criterion.value ?? selected,
            });
        }
    }

    const quickFilters = [
        {
            label: 'Overdue',
            icon: AlertTriangle,
            active: filters.deadline_health === 'overdue',
            change: {
                deadline_health:
                    filters.deadline_health === 'overdue' ? null : 'overdue',
            },
        },
        {
            label: 'Due in 7 days',
            icon: CalendarClock,
            active: filters.deadline_health === 'due_soon',
            change: {
                deadline_health:
                    filters.deadline_health === 'due_soon' ? null : 'due_soon',
            },
        },
        {
            label: 'Urgent',
            icon: Flame,
            active: filters.priority === 'urgent',
            change: {
                priority: filters.priority === 'urgent' ? null : 'urgent',
            },
        },
    ];

    return (
        <div className="rounded-xl border bg-card shadow-xs">
            <div className="flex flex-col gap-3 border-b px-4 pt-3 sm:flex-row sm:items-center sm:justify-between">
                <nav aria-label="Requirement status" className="flex gap-1">
                    {(
                        [
                            { key: 'active', label: 'Active' },
                            { key: 'on_hold', label: 'On hold' },
                            { key: 'history', label: 'History' },
                        ] as const
                    ).map(({ key, label }) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={filters.tab === key}
                            onClick={() => onChange({ tab: key })}
                            className={cn(
                                'flex items-center gap-2 border-b-2 border-transparent px-2 pb-3 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground focus-visible:rounded focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-3',
                                filters.tab === key &&
                                    'border-primary text-primary',
                            )}
                        >
                            {label}
                            <span
                                className={cn(
                                    'rounded-md bg-muted px-1.5 py-0.5 text-xs tabular-nums',
                                    filters.tab === key && 'bg-primary/10',
                                )}
                            >
                                {tab_counts[key].toLocaleString()}
                            </span>
                        </button>
                    ))}
                </nav>
                <p
                    role="status"
                    aria-live="polite"
                    className="pb-3 text-xs text-muted-foreground"
                >
                    {isLoading
                        ? 'Updating requirements…'
                        : `${total.toLocaleString()} ${total === 1 ? 'requirement' : 'requirements'}${chips.length || filters.search ? ' matching' : ''}`}
                </p>
            </div>
            <div className="space-y-3 p-4">
                <div className="flex gap-2">
                    <div className="relative min-w-0 flex-1">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            aria-label="Search requirements"
                            placeholder="Search by reference, client, project or role…"
                            value={searchInput}
                            onChange={(event) =>
                                onSearchChange(event.target.value)
                            }
                            className="h-10 pr-10 pl-9"
                        />
                        {searchInput && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Clear search"
                                onClick={onClearSearch}
                                className="absolute top-1 right-1 size-8"
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-10 gap-2"
                        onClick={onOpenFilters}
                        aria-label={
                            chips.length
                                ? `Filters active (${chips.length})`
                                : 'Open filters'
                        }
                    >
                        <SlidersHorizontal
                            className="size-4"
                            aria-hidden="true"
                        />
                        <span className="hidden sm:inline">Filters</span>
                        {chips.length > 0 && (
                            <span className="rounded bg-primary/10 px-1.5 text-xs text-primary">
                                {chips.length}
                            </span>
                        )}
                    </Button>
                </div>
                {filters.tab !== 'history' && (
                    <div
                        className="flex flex-wrap items-center gap-2"
                        role="group"
                        aria-label="Quick filters"
                    >
                        <span className="mr-1 text-xs text-muted-foreground">
                            Quick filters
                        </span>
                        {quickFilters.map(
                            ({ label, icon: Icon, active, change }) => (
                                <Button
                                    key={label}
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    aria-pressed={active}
                                    onClick={() => onChange(change)}
                                    className={cn(
                                        'h-8 gap-1.5 rounded-full text-xs shadow-none',
                                        active &&
                                            'border-primary/40 bg-primary/10 text-primary hover:bg-primary/15',
                                    )}
                                >
                                    <Icon
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {label}
                                </Button>
                            ),
                        )}
                    </div>
                )}
                {(chips.length > 0 || filters.search) && (
                    <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                        {chips.map((chip) => (
                            <button
                                key={chip.key}
                                type="button"
                                aria-label={`Remove ${chip.label.toLowerCase()} filter`}
                                onClick={() => onChange({ [chip.key]: null })}
                                className="inline-flex max-w-full items-center gap-2 rounded-md border bg-muted/40 px-2.5 py-1.5 text-xs hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className="truncate">
                                    <span className="text-muted-foreground">
                                        {chip.label}:
                                    </span>{' '}
                                    {chip.value}
                                </span>
                                <X
                                    className="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                            </button>
                        ))}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={onReset}
                            className="h-8 text-xs text-muted-foreground"
                        >
                            Clear search & filters
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
