import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';
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
    const [open, setOpenState] = useState(false);
    const [commandMenuMounted, setCommandMenuMounted] = useState(false);

    const setOpen: React.Dispatch<React.SetStateAction<boolean>> = useCallback(
        (value) => {
            setOpenState((prev) => {
                const next = typeof value === 'function' ? value(prev) : value;

                if (next) {
                    setCommandMenuMounted(true);
                }

                return next;
            });
        },
        [],
    );

    useEffect(() => {
        const down = (e: KeyboardEvent) => {
            if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                setOpen((open) => !open);
            }
        };
        document.addEventListener('keydown', down);

        return () => document.removeEventListener('keydown', down);
    }, [setOpen]);

    return (
        <SearchContext value={{ open, setOpen }}>
            {children}
            {commandMenuMounted ? <CommandMenu /> : null}
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
