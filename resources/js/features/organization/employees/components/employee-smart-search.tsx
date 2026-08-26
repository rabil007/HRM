import { Sparkles } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { EmployeeFilters } from '@/features/organization/employees/components/employee-filters-sheet';
import {
    SMART_SEARCH_OVERRIDDEN_COPY,
    directoryScopeChips,
    formatUnresolvedItem,
    smartSearchResolvedPreview,
    smartSearchResultCopyKind,
} from '@/features/organization/employees/lib/employee-smart-search';
import type { useEmployeeSmartSearch } from '@/features/organization/employees/use-employee-smart-search';

export function EmployeeSmartSearch({
    currentFilters,
    search,
}: {
    currentFilters: EmployeeFilters;
    search: ReturnType<typeof useEmployeeSmartSearch>;
}) {
    const { prompt, loading, error, result, owned, onPromptChange, onSubmit } =
        search;

    const previewChips =
        result === null
            ? []
            : smartSearchResolvedPreview(result.applied, owned, currentFilters);
    const scopeChips = directoryScopeChips(currentFilters);
    const resultCopy = smartSearchResultCopyKind({ result, previewChips });

    return (
        <section
            aria-labelledby="employee-smart-search-heading"
            className="mb-8 rounded-xl border border-border/80 bg-card p-4 shadow-sm dark:border-white/5 dark:bg-white/5"
        >
            <form onSubmit={onSubmit} className="space-y-3" aria-busy={loading}>
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
                            Use Smart Search for filters like “active Filipino
                            crew” or “employees missing DOB”. Use the regular
                            search above for a specific name, employee number,
                            email or phone.
                        </p>
                    </div>
                </div>

                <div className="relative min-w-0">
                    <Label
                        htmlFor="employee-smart-search-prompt"
                        className="sr-only"
                    >
                        Smart Search
                    </Label>
                    <Input
                        id="employee-smart-search-prompt"
                        value={prompt}
                        onChange={(event) => onPromptChange(event.target.value)}
                        placeholder="e.g. employees without email"
                        autoComplete="off"
                        maxLength={200}
                        aria-describedby="employee-smart-search-help"
                        className="h-12 rounded-xl border-input bg-background/80 pr-24 dark:border-white/5 dark:bg-white/5"
                    />
                    {loading ? (
                        <span className="absolute top-1/2 right-3 flex -translate-y-1/2 items-center gap-1.5 text-xs text-muted-foreground">
                            <Spinner className="size-3.5" />
                            <span>Searching...</span>
                        </span>
                    ) : null}
                </div>
            </form>

            {error ? (
                <p role="alert" className="mt-3 text-sm text-destructive">
                    {error}
                </p>
            ) : null}

            {result ? (
                <div className="mt-4 space-y-3 border-t border-border/70 pt-4 dark:border-white/10">
                    {resultCopy === 'applied' ? (
                        <div className="space-y-2">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Results filtered
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
                    ) : resultCopy === 'overridden' ? (
                        <p className="text-sm text-muted-foreground">
                            {SMART_SEARCH_OVERRIDDEN_COPY}
                        </p>
                    ) : (
                        <div className="space-y-1 text-sm text-muted-foreground">
                            <p>No Smart Search filters were applied.</p>
                            <p>The current employee list was not changed.</p>
                        </div>
                    )}

                    {scopeChips.length > 0 ? (
                        <div className="space-y-2">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Directory scope
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {scopeChips.map((chip) => (
                                    <Badge
                                        key={chip.key}
                                        variant="secondary"
                                        className="font-normal"
                                    >
                                        {chip.title} · {chip.label}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    ) : null}

                    {result.unresolved.length > 0 ? (
                        <ul className="space-y-1 text-sm text-foreground">
                            {result.unresolved.map((item) => (
                                <li
                                    key={`${item.field}:${item.term}:${item.reason}`}
                                >
                                    Could not apply ·{' '}
                                    {formatUnresolvedItem(item)}
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {result.ambiguous.length > 0 ? (
                        <ul className="space-y-1 text-sm text-foreground">
                            {result.ambiguous.map((item) => (
                                <li
                                    key={`ambiguous:${item.field}:${item.term}:${item.reason}`}
                                >
                                    Needs clarification ·{' '}
                                    {formatUnresolvedItem(item)}
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {result.unsupported.length > 0 ? (
                        <ul className="space-y-1 text-sm text-foreground">
                            {result.unsupported.map((term) => (
                                <li key={term}>Not supported yet · {term}</li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
