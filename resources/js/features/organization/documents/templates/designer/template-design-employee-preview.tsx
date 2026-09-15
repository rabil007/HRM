import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    employeeValues,
    searchEmployees,
} from '@/actions/App/Http/Controllers/Organization/DocumentGenerationTemplatePreviewController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type DesignEmployeePreview = {
    id: number;
    name: string;
    employee_no: string | null;
    values: Record<string, string>;
};

type SearchRow = {
    id: number;
    name: string;
    employee_no: string | null;
};

export function TemplateDesignEmployeePreviewPicker({
    templateId,
    selected,
    onSelect,
    onClear,
}: {
    templateId: number;
    selected: DesignEmployeePreview | null;
    onSelect: (employee: DesignEmployeePreview) => void;
    onClear: () => void;
}) {
    const http = useHttp();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchRow[]>([]);
    const [loading, setLoading] = useState(false);
    const requestIdRef = useRef(0);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        return () => {
            if (debounceRef.current !== null) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const runSearch = (value: string) => {
        setQuery(value);

        if (debounceRef.current !== null) {
            clearTimeout(debounceRef.current);
        }

        if (value.trim() === '') {
            requestIdRef.current += 1;
            setResults([]);
            setLoading(false);

            return;
        }

        setLoading(true);
        debounceRef.current = setTimeout(() => {
            debounceRef.current = null;
            const requestId = requestIdRef.current + 1;
            requestIdRef.current = requestId;

            void http
                .get(
                    searchEmployees.url(templateId, {
                        query: { q: value.trim() },
                    }),
                )
                .then((data) => {
                    if (requestId !== requestIdRef.current) {
                        return;
                    }

                    const employees = (data as { employees?: SearchRow[] })
                        .employees;

                    setResults(Array.isArray(employees) ? employees : []);
                    setLoading(false);
                })
                .catch(() => {
                    if (requestId !== requestIdRef.current) {
                        return;
                    }

                    setResults([]);
                    setLoading(false);
                });
        }, 400);
    };

    const pick = (row: SearchRow) => {
        void http
            .get(employeeValues.url({ template: templateId, employee: row.id }))
            .then((data) => {
                const payload = data as DesignEmployeePreview;

                if (!payload?.id || !payload.values) {
                    return;
                }

                onSelect(payload);
                setQuery('');
                setResults([]);
            });
    };

    return (
        <div className="relative min-w-40">
            {selected ? (
                <div className="flex items-center gap-1">
                    <span className="max-w-36 truncate text-xs text-foreground">
                        {selected.name}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7 px-1.5 text-xs"
                        onClick={onClear}
                    >
                        Sample
                    </Button>
                </div>
            ) : (
                <Input
                    value={query}
                    onChange={(event) => runSearch(event.target.value)}
                    placeholder="Preview employee…"
                    className="h-7 w-44 text-xs"
                />
            )}
            {!selected && (loading || results.length > 0) && (
                <div className="absolute top-full right-0 z-20 mt-1 w-56 rounded-md border border-border bg-popover p-1 shadow-md">
                    {loading && results.length === 0 ? (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">
                            Searching…
                        </p>
                    ) : (
                        results.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                className="flex w-full flex-col rounded px-2 py-1.5 text-left hover:bg-muted"
                                onClick={() => pick(row)}
                            >
                                <span className="truncate text-xs font-medium">
                                    {row.name}
                                </span>
                                {row.employee_no ? (
                                    <span className="truncate font-mono text-[10px] text-muted-foreground">
                                        {row.employee_no}
                                    </span>
                                ) : null}
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
