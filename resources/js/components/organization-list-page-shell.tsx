import { Filter } from 'lucide-react';
import type { ReactNode } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';

type OrganizationListPageShellProps = {
    title: ReactNode;
    description?: string;
    kicker?: string;
    headerRight?: ReactNode;
    aboveSearch?: ReactNode;
    search: {
        value: string;
        onChange: (value: string) => void;
        placeholder: string;
        right?: ReactNode;
    };
    filtersButton?: {
        onClick: () => void;
        activeFiltersCount?: number;
    };
    children: ReactNode;
    pagination?: ReactNode;
};

export function OrganizationListPageShell({
    title,
    description,
    kicker,
    headerRight,
    aboveSearch,
    search,
    filtersButton,
    children,
    pagination,
}: OrganizationListPageShellProps) {
    return (
        <Main>
            <PageHeader
                kicker={kicker}
                title={title}
                description={description}
                right={headerRight}
            />

            {aboveSearch}

            <SearchBar
                placeholder={search.placeholder}
                value={search.value}
                onChange={search.onChange}
                right={
                    <>
                        {search.right}
                        {filtersButton ? (
                            <Button
                                type="button"
                                variant="secondary"
                                className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                                onClick={filtersButton.onClick}
                            >
                                <Filter className="mr-2 h-4 w-4" />
                                Filters
                                {filtersButton.activeFiltersCount ? (
                                    <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                        {filtersButton.activeFiltersCount}
                                    </span>
                                ) : null}
                            </Button>
                        ) : null}
                    </>
                }
            />

            {children}

            {pagination}
        </Main>
    );
}
