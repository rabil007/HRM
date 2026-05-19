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
};

export function EmployeeProfileNavigation({
    navigation,
    onNavigate,
    className,
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
                'group inline-flex items-center overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.04] shadow-inner shadow-black/20 backdrop-blur-sm transition-colors hover:border-white/[0.12] hover:bg-white/[0.06]',
                className,
            )}
        >
            {/* Prev button */}
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
                className="flex h-9 w-9 items-center justify-center text-zinc-500 transition-colors hover:bg-white/[0.08] hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-30"
            >
                <ChevronLeft className="size-3.5" />
            </button>

            {/* Counter */}
            <div className="flex h-9 min-w-[3.25rem] items-center justify-center border-x border-white/[0.07] px-2">
                <span className="text-[11px] font-semibold tabular-nums text-zinc-400">
                    {navigation.position} / {navigation.total}
                </span>
            </div>

            {/* Next button */}
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
                className="flex h-9 w-9 items-center justify-center text-zinc-500 transition-colors hover:bg-white/[0.08] hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-30"
            >
                <ChevronRight className="size-3.5" />
            </button>
        </div>
    );
}
