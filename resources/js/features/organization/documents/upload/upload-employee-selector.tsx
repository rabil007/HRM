import { useHttp } from '@inertiajs/react';
import { Loader2, Search, User, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactElement } from 'react';
import DocumentUploadEmployeeSearchController from '@/actions/App/Http/Controllers/Organization/DocumentUploadEmployeeSearchController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface DocumentUploadEmployeeOption {
    id: number;
    name: string;
    employee_no: string | null;
}

interface UploadEmployeeSelectorProps {
    selectedEmployee: DocumentUploadEmployeeOption | null;
    onSelect: (employee: DocumentUploadEmployeeOption | null) => void;
    disabled?: boolean;
    className?: string;
}

const DEBOUNCE_MS = 250;

export function UploadEmployeeSelector({
    selectedEmployee,
    onSelect,
    disabled = false,
    className,
}: UploadEmployeeSelectorProps): ReactElement {
    const http = useHttp();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<DocumentUploadEmployeeOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [isOpen, setIsOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);

    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const requestIdRef = useRef(0);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const clearPending = useCallback(() => {
        if (debounceRef.current !== null) {
            clearTimeout(debounceRef.current);
            debounceRef.current = null;
        }
    }, []);

    useEffect(() => {
        return () => {
            clearPending();
        };
    }, [clearPending]);

    // Handle clicks outside the dropdown to close it
    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (
                containerRef.current &&
                !containerRef.current.contains(event.target as Node)
            ) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    const handleSearch = useCallback(
        (value: string) => {
            setQuery(value);
            const trimmed = value.trim();

            if (trimmed.length === 0) {
                requestIdRef.current += 1;
                clearPending();
                setResults([]);
                setLoading(false);
                setIsOpen(false);
                setActiveIndex(-1);

                return;
            }

            setLoading(true);
            setIsOpen(true);
            clearPending();

            debounceRef.current = setTimeout(() => {
                debounceRef.current = null;
                const requestId = requestIdRef.current + 1;
                requestIdRef.current = requestId;

                void http
                    .get(
                        DocumentUploadEmployeeSearchController.url({
                            query: { q: trimmed },
                        }),
                    )
                    .then((data) => {
                        if (requestId !== requestIdRef.current) {
                            return;
                        }

                        const list = (data ??
                            []) as DocumentUploadEmployeeOption[];
                        setResults(list);
                        setLoading(false);
                        setActiveIndex(-1);
                    })
                    .catch(() => {
                        if (requestId !== requestIdRef.current) {
                            return;
                        }

                        setResults([]);
                        setLoading(false);
                        setActiveIndex(-1);
                    });
            }, DEBOUNCE_MS);
        },
        [clearPending, http],
    );

    const handleSelect = (employee: DocumentUploadEmployeeOption) => {
        onSelect(employee);
        setQuery('');
        setResults([]);
        setIsOpen(false);
        setActiveIndex(-1);
    };

    const handleClear = () => {
        onSelect(null);
        setQuery('');
        setResults([]);
        setIsOpen(false);
        setActiveIndex(-1);
        setTimeout(() => {
            inputRef.current?.focus();
        }, 50);
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!isOpen) {
            if (e.key === 'ArrowDown' && results.length > 0) {
                setIsOpen(true);
                setActiveIndex(0);
                e.preventDefault();
            }

            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((prev) =>
                prev < results.length - 1 ? prev + 1 : 0,
            );
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((prev) =>
                prev > 0 ? prev - 1 : results.length - 1,
            );
        } else if (e.key === 'Enter') {
            e.preventDefault();

            if (activeIndex >= 0 && activeIndex < results.length) {
                handleSelect(results[activeIndex]);
            } else if (results.length === 1) {
                handleSelect(results[0]);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            setIsOpen(false);
            setActiveIndex(-1);
        }
    };

    if (selectedEmployee) {
        return (
            <div
                className={cn(
                    'flex items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 p-3',
                    className,
                )}
            >
                <div className="flex min-w-0 items-center gap-2.5">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-primary">
                        <User className="h-4 w-4" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <span className="truncate text-sm font-semibold text-foreground">
                                {selectedEmployee.name}
                            </span>
                            {selectedEmployee.employee_no ? (
                                <Badge
                                    variant="secondary"
                                    className="shrink-0 font-mono text-xs font-normal"
                                >
                                    #{selectedEmployee.employee_no}
                                </Badge>
                            ) : null}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Target employee for uploaded documents
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={handleClear}
                    className="h-8 shrink-0 px-2.5 text-xs"
                >
                    <X className="mr-1 h-3.5 w-3.5" />
                    Change
                </Button>
            </div>
        );
    }

    return (
        <div
            ref={containerRef}
            className={cn('relative space-y-1.5', className)}
        >
            <label
                htmlFor="document-upload-employee-input"
                className="text-xs font-medium text-foreground"
            >
                Target Employee <span className="text-destructive">*</span>
            </label>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    id="document-upload-employee-input"
                    ref={inputRef}
                    type="text"
                    role="combobox"
                    aria-expanded={isOpen}
                    aria-autocomplete="list"
                    aria-controls="document-upload-employee-listbox"
                    placeholder="Search active employee by name or ID..."
                    value={query}
                    disabled={disabled}
                    onChange={(e) => handleSearch(e.target.value)}
                    onFocus={() => {
                        if (query.trim().length > 0 && results.length > 0) {
                            setIsOpen(true);
                        }
                    }}
                    onKeyDown={handleKeyDown}
                    className="h-9 pr-9 pl-9 text-sm"
                />
                {loading ? (
                    <div className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" />
                    </div>
                ) : null}
            </div>

            {isOpen && (
                <div
                    id="document-upload-employee-listbox"
                    role="listbox"
                    className="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-border bg-popover p-1 shadow-lg"
                >
                    {loading && results.length === 0 ? (
                        <div className="flex items-center justify-center gap-2 py-4 text-xs text-muted-foreground">
                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            Searching active employees…
                        </div>
                    ) : results.length === 0 ? (
                        <div className="py-4 text-center text-xs text-muted-foreground">
                            {query.trim().length === 0
                                ? 'Type an employee name or ID to search.'
                                : `No active employees found matching "${query}".`}
                        </div>
                    ) : (
                        results.map((employee, index) => {
                            const isSelected = activeIndex === index;

                            return (
                                <button
                                    key={employee.id}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => handleSelect(employee)}
                                    onMouseEnter={() => setActiveIndex(index)}
                                    className={cn(
                                        'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition-colors',
                                        isSelected
                                            ? 'bg-accent font-medium text-accent-foreground'
                                            : 'text-foreground hover:bg-muted/50',
                                    )}
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <User className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                        <span className="truncate">
                                            {employee.name}
                                        </span>
                                    </div>
                                    {employee.employee_no ? (
                                        <Badge
                                            variant="outline"
                                            className="ml-2 shrink-0 font-mono text-[11px] font-normal"
                                        >
                                            #{employee.employee_no}
                                        </Badge>
                                    ) : null}
                                </button>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
