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
            show.url({ employee: employeeId }, { query: navigation.list_query }),
            { preserveScroll: true },
        );
    };

    return (
        <div
            className={cn(
                'inline-flex shrink-0 items-stretch',
                embedded
                    ? 'h-full'
                    : 'group overflow-hidden rounded-2xl border border-white/8 bg-white/4 shadow-inner shadow-black/20 backdrop-blur-sm transition-colors hover:border-white/12 hover:bg-white/6',
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
                    'flex w-10 items-center justify-center transition-colors disabled:cursor-not-allowed disabled:opacity-30',
                    embedded
                        ? 'text-muted-foreground hover:bg-muted/40 hover:text-foreground'
                        : 'h-9 text-zinc-500 hover:bg-white/8 hover:text-zinc-200',
                )}
            >
                <ChevronLeft className="size-4" />
            </button>

            <div
                className={cn(
                    'flex min-w-18 items-center justify-center px-3',
                    embedded
                        ? 'border-x border-border/80'
                        : 'h-9 border-x border-white/7',
                )}
            >
                <span
                    className={cn(
                        'text-xs font-semibold tabular-nums',
                        embedded ? 'text-foreground' : 'text-zinc-400',
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
                    'flex w-10 items-center justify-center transition-colors disabled:cursor-not-allowed disabled:opacity-30',
                    embedded
                        ? 'text-muted-foreground hover:bg-muted/40 hover:text-foreground'
                        : 'h-9 text-zinc-500 hover:bg-white/8 hover:text-zinc-200',
                )}
            >
                <ChevronRight className="size-4" />
            </button>
        </div>
    );
}
