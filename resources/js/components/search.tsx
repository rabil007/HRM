import { SearchIcon } from 'lucide-react';
import { useSearch } from '@/context/search-provider';
import { cn } from '@/lib/utils';
import { Button } from './ui/button';

type SearchProps = {
    className?: string;
    type?: React.HTMLInputTypeAttribute;
    placeholder?: string;
};

export function Search({
    className = '',
    placeholder = 'Search',
}: SearchProps) {
    const { setOpen } = useSearch();

    return (
        <Button
            variant="outline"
            className={cn(
                'group relative size-8 flex-none justify-center rounded-md bg-muted/25 p-0 text-sm font-normal text-muted-foreground shadow-none hover:bg-accent sm:h-8 sm:w-40 sm:justify-start sm:px-4 sm:py-2 sm:pe-12 md:flex-none lg:w-52 xl:w-64',
                className,
            )}
            onClick={() => setOpen(true)}
        >
            <SearchIcon
                aria-hidden="true"
                className="sm:absolute sm:start-1.5 sm:top-1/2 sm:-translate-y-1/2"
                size={16}
            />
            <span className="sr-only sm:not-sr-only sm:ms-4">
                {placeholder}
            </span>
            <kbd className="pointer-events-none absolute end-[0.3rem] top-[0.3rem] hidden h-5 items-center gap-1 rounded border bg-muted px-1.5 font-mono text-[10px] font-medium opacity-100 select-none group-hover:bg-accent sm:flex">
                <span className="text-xs">⌘</span>K
            </kbd>
        </Button>
    );
}
