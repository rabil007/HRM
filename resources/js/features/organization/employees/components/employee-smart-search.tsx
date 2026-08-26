import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { EmployeeFilters } from '@/features/organization/employees/components/employee-filters-sheet';
import { interpretEmployeeSmartSearch } from '@/features/organization/employees/interpret-employee-smart-search';
import {
    formatUnresolvedItem,
    hasApplyableSmartSearchFilters,
    mergeSmartSearchFilters,
    smartSearchErrorMessage,
    smartSearchResolvedPreview,
} from '@/features/organization/employees/lib/employee-smart-search';
import type { NormalizedSmartSearchResult } from '@/features/organization/employees/lib/employee-smart-search';

export function EmployeeSmartSearch({
    currentFilters,
    onApplyFilters,
}: {
    currentFilters: EmployeeFilters;
    onApplyFilters: (next: EmployeeFilters) => void;
}) {
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<NormalizedSmartSearchResult | null>(
        null,
    );

    const canApply =
        result !== null && hasApplyableSmartSearchFilters(result.filters);
    const previewChips =
        result === null
            ? []
            : smartSearchResolvedPreview(result.filters, result.labels);

    const clearPreview = () => {
        setResult(null);
        setError(null);
    };

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (loading) {
            return;
        }

        const nextPrompt = prompt.trim();

        if (nextPrompt === '') {
            return;
        }

        setLoading(true);
        setError(null);
        setResult(null);

        try {
            const interpretation =
                await interpretEmployeeSmartSearch(nextPrompt);
            setResult(interpretation);
        } catch (caught) {
            const message =
                caught instanceof Error
                    ? caught.message
                    : smartSearchErrorMessage(0);

            setError(message);
        } finally {
            setLoading(false);
        }
    };

    const handleApply = () => {
        if (!canApply || result === null) {
            return;
        }

        onApplyFilters(mergeSmartSearchFilters(currentFilters, result.filters));
        setResult(null);
        setError(null);
    };

    return (
        <section
            aria-labelledby="employee-smart-search-heading"
            className="mb-8 rounded-xl border border-border/80 bg-card p-4 shadow-sm dark:border-white/5 dark:bg-white/5"
        >
            <form
                onSubmit={handleSubmit}
                className="space-y-3"
                aria-busy={loading}
            >
                <div className="flex items-start gap-3">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Sparkles className="h-4 w-4" aria-hidden="true" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2
                                id="employee-smart-search-heading"
                                className="text-sm font-semibold tracking-tight text-foreground"
                            >
                                Smart Search
                            </h2>
                            <Badge
                                variant="secondary"
                                className="text-[10px] font-medium"
                            >
                                Beta
                            </Badge>
                        </div>
                        <p
                            id="employee-smart-search-help"
                            className="mt-0.5 text-xs text-muted-foreground"
                        >
                            Describe the employees you want to find.
                        </p>
                    </div>
                </div>

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="min-w-0 flex-1">
                        <Label
                            htmlFor="employee-smart-search-prompt"
                            className="sr-only"
                        >
                            Smart Search prompt
                        </Label>
                        <Input
                            id="employee-smart-search-prompt"
                            value={prompt}
                            onChange={(event) => setPrompt(event.target.value)}
                            placeholder="e.g. active Filipino AB crew in Crewing"
                            autoComplete="off"
                            maxLength={200}
                            disabled={loading}
                            aria-describedby="employee-smart-search-help"
                            className="h-12 rounded-xl border-input bg-background/80 dark:border-white/5 dark:bg-white/5"
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={loading || prompt.trim() === ''}
                        className="h-12 w-full rounded-xl sm:w-auto sm:shrink-0"
                    >
                        {loading ? <Spinner className="mr-1" /> : null}
                        Interpret
                    </Button>
                </div>
            </form>

            {error ? (
                <p role="alert" className="mt-3 text-sm text-destructive">
                    {error}
                </p>
            ) : null}

            {result ? (
                <div className="mt-4 space-y-3 border-t border-border/70 pt-4 dark:border-white/10">
                    {previewChips.length > 0 ? (
                        <div className="space-y-2">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Understood
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {previewChips.map((chip) => (
                                    <Badge
                                        key={chip.key}
                                        variant="outline"
                                        className="border-primary/25 bg-primary/5 font-normal"
                                    >
                                        {chip.title} · {chip.label}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No supported Employee Directory filters were found.
                            Try rephrasing your search.
                        </p>
                    )}

                    {result.unresolved.length > 0 ? (
                        <div className="space-y-1.5">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Could not apply
                            </p>
                            <ul className="space-y-1 text-sm text-foreground">
                                {result.unresolved.map((item) => (
                                    <li
                                        key={`${item.field}:${item.term}:${item.reason}`}
                                    >
                                        {formatUnresolvedItem(item)}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {result.unsupported.length > 0 ? (
                        <div className="space-y-1.5">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Not supported yet
                            </p>
                            <ul className="list-disc space-y-1 pl-5 text-sm text-foreground">
                                {result.unsupported.map((term) => (
                                    <li key={term}>{term}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-10 rounded-xl"
                            onClick={clearPreview}
                        >
                            Clear
                        </Button>
                        {canApply ? (
                            <Button
                                type="button"
                                className="h-10 rounded-xl"
                                onClick={handleApply}
                            >
                                Apply Filters
                            </Button>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </section>
    );
}
