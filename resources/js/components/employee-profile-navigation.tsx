import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { cn } from '@/lib/utils';
import type { EmployeeNavigation } from '@/pages/organization/employee-page.types';

type EmployeeProfileNavigationProps = {
    navigation: EmployeeNavigation;
    onNavigate?: (employeeId: number) => void;
    className?: string;
    embedded?: boolean;
};

export function EmployeeProfileNavigation({
    navigation,
    onNavigate,
    className,
    embedded = false,
}: EmployeeProfileNavigationProps): ReactElement | null {
    if (navigation.total <= 0) {
        return null;
    }

    const visitEmployee = (employeeId: number) => {
        if (onNavigate) {
            onNavigate(employeeId);

            return;
        }

        router.visit(
            show.url(
                { employee: employeeId },
                { query: navigation.list_query },
            ),
            { preserveScroll: true },
        );
    };

    const embeddedSegmentClass = embedded
        ? 'flex h-13 min-h-13 items-center justify-center'
        : 'flex h-9 items-center justify-center';

    return (
        <div
            className={cn(
                'inline-flex shrink-0 items-center',
                embedded
                    ? 'h-13 min-h-13'
                    : 'group overflow-hidden rounded-2xl border border-border/80 bg-muted/40 shadow-inner backdrop-blur-sm transition-colors hover:border-border hover:bg-muted/80 dark:border-white/8 dark:bg-white/4 dark:hover:border-white/12 dark:hover:bg-white/6',
                className,
            )}
        >
            <button
                type="button"
                aria-label="Previous employee"
                title="Previous employee"
                disabled={navigation.previous_id === null}
                onClick={() => {
                    if (navigation.previous_id !== null) {
                        visitEmployee(navigation.previous_id);
                    }
                }}
                className={cn(
                    embeddedSegmentClass,
                    'w-10 transition-colors disabled:cursor-not-allowed disabled:opacity-30',
                    embedded
                        ? 'text-muted-foreground hover:bg-muted/40 hover:text-foreground'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground dark:text-zinc-500 dark:hover:bg-white/8 dark:hover:text-zinc-200',
                )}
            >
                <ChevronLeft className="size-4 shrink-0" />
            </button>

            <div
                className={cn(
                    embeddedSegmentClass,
                    'min-w-18 px-3',
                    embedded
                        ? 'border-x border-border/80'
                        : 'border-x border-border/80 dark:border-white/7',
                )}
            >
                <span
                    className={cn(
                        'text-xs leading-none font-semibold tabular-nums',
                        embedded
                            ? 'text-foreground'
                            : 'text-muted-foreground dark:text-zinc-400',
                    )}
                >
                    {navigation.position} / {navigation.total}
                </span>
            </div>

            <button
                type="button"
                aria-label="Next employee"
                title="Next employee"
                disabled={navigation.next_id === null}
                onClick={() => {
                    if (navigation.next_id !== null) {
                        visitEmployee(navigation.next_id);
                    }
                }}
                className={cn(
                    embeddedSegmentClass,
                    'w-10 transition-colors disabled:cursor-not-allowed disabled:opacity-30',
                    embedded
                        ? 'text-muted-foreground hover:bg-muted/40 hover:text-foreground'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground dark:text-zinc-500 dark:hover:bg-white/8 dark:hover:text-zinc-200',
                )}
            >
                <ChevronRight className="size-4 shrink-0" />
            </button>
        </div>
    );
}
