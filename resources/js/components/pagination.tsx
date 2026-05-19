import { ChevronLeft, ChevronRight } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { PAGINATION_PER_PAGE_OPTIONS } from '@/types/pagination';

type PaginationProps = {
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    perPage?: number;
    perPageOptions?: readonly number[];
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
    label?: string;
    showSummary?: boolean;
};

function buildPages(current: number, last: number): (number | '...')[] {
    return Array.from({ length: last }, (_, i) => i + 1)
        .filter((p) => p === 1 || p === last || Math.abs(p - current) <= 2)
        .reduce<(number | '...')[]>((acc, p, idx, arr) => {
            if (idx > 0 && (p as number) - (arr[idx - 1] as number) > 1) {
                acc.push('...');
            }

            acc.push(p);

            return acc;
        }, []);
}

export function Pagination({
    currentPage,
    lastPage,
    from,
    to,
    total,
    perPage,
    perPageOptions = PAGINATION_PER_PAGE_OPTIONS,
    onPageChange,
    onPerPageChange,
    label = 'results',
    showSummary = true,
}: PaginationProps) {
    if (total === 0) {
        return null;
    }

    const pages = buildPages(currentPage, lastPage);
    const options =
        perPage && ![...perPageOptions].includes(perPage)
            ? [...perPageOptions, perPage].sort((a, b) => a - b)
            : perPageOptions;

    return (
        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-wrap items-center gap-3">
                {onPerPageChange && perPage !== undefined ? (
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">Rows</span>
                        <AppSelect
                            value={String(perPage)}
                            onValueChange={(v) => onPerPageChange(Number(v))}
                            variant="card"
                            size="sm"
                        >
                            {options.map((option) => (
                                <AppSelectItem key={option} value={String(option)}>
                                    {option}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>
                ) : null}

                {showSummary ? (
                    <p className="text-xs text-muted-foreground">
                        Showing <span className="font-medium text-foreground">{from ?? 0}</span>–
                        <span className="font-medium text-foreground">{to ?? 0}</span> of{' '}
                        <span className="font-medium text-foreground">{total}</span> {label}
                    </p>
                ) : null}
            </div>

            {lastPage > 1 ? (
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="secondary"
                        className="h-9 rounded-lg px-3 text-sm"
                        disabled={currentPage === 1}
                        onClick={() => onPageChange(currentPage - 1)}
                    >
                        <ChevronLeft className="mr-1 h-4 w-4" />
                        Previous
                    </Button>

                    {pages.map((p, i) =>
                        p === '...' ? (
                            <span key={`ellipsis-${i}`} className="px-1 text-xs text-muted-foreground select-none">
                                …
                            </span>
                        ) : (
                            <Button
                                key={p}
                                type="button"
                                variant={p === currentPage ? 'default' : 'ghost'}
                                size="icon"
                                className="h-9 w-9 rounded-lg text-xs font-medium"
                                onClick={() => onPageChange(p as number)}
                                aria-label={`Page ${p}`}
                                aria-current={p === currentPage ? 'page' : undefined}
                            >
                                {p}
                            </Button>
                        ),
                    )}

                    <Button
                        type="button"
                        variant="secondary"
                        className="h-9 rounded-lg px-3 text-sm"
                        disabled={currentPage === lastPage}
                        onClick={() => onPageChange(currentPage + 1)}
                    >
                        Next
                        <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
