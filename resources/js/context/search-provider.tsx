import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { CommandMenu } from '@/components/command-menu';

type SearchContextType = {
    open: boolean;
    setOpen: React.Dispatch<React.SetStateAction<boolean>>;
};

const SearchContext = createContext<SearchContextType | null>(null);

type SearchProviderProps = {
    children: React.ReactNode;
};

export function SearchProvider({ children }: SearchProviderProps) {
    const [open, setOpen] = useState(false);
    const commandMenuMountedRef = useRef(false);

    if (open) {
        commandMenuMountedRef.current = true;
    }

    useEffect(() => {
        const down = (e: KeyboardEvent) => {
            if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                setOpen((open) => !open);
            }
        };
        document.addEventListener('keydown', down);

        return () => document.removeEventListener('keydown', down);
    }, []);

    return (
        <SearchContext value={{ open, setOpen }}>
            {children}
            {commandMenuMountedRef.current ? <CommandMenu /> : null}
        </SearchContext>
    );
}

export const useSearch = () => {
    const searchContext = useContext(SearchContext);

    if (!searchContext) {
        throw new Error('useSearch has to be used within SearchProvider');
    }

    return searchContext;
};
