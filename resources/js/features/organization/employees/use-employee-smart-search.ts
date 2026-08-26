import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { EmployeeFilters } from '@/features/organization/employees/components/employee-filters-sheet';
import {
    EmployeeSmartSearchAbortedError,
    EmployeeSmartSearchRequestError,
    interpretEmployeeSmartSearch,
} from '@/features/organization/employees/interpret-employee-smart-search';
import {
    SMART_SEARCH_CACHE_LIMIT,
    SMART_SEARCH_DEBOUNCE_MS,
    employeeDirectoryFiltersEqual,
    hasApplyableSmartSearchFilters,
    isSmartSearchPromptReady,
    normalizeSmartSearchPrompt,
    reconcileServerWorkingFilters,
    reconcileSmartSearchOwnership,
    replaceSmartSearchOwnedFilters,
    smartSearchErrorMessage,
    SmartSearchInterpretationCache,
} from '@/features/organization/employees/lib/employee-smart-search';
import type {
    NormalizedSmartSearchResult,
    SmartSearchFilters,
} from '@/features/organization/employees/lib/employee-smart-search';

export function useEmployeeSmartSearch({
    currentFilters,
    onApplyFilters,
    debounceMs = SMART_SEARCH_DEBOUNCE_MS,
}: {
    currentFilters: EmployeeFilters;
    onApplyFilters: (next: EmployeeFilters) => void;
    debounceMs?: number;
}): {
    prompt: string;
    loading: boolean;
    error: string | null;
    result: NormalizedSmartSearchResult | null;
    owned: SmartSearchFilters;
    workingFilters: EmployeeFilters;
    onPromptChange: (value: string) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onManualFiltersChange: (next: EmployeeFilters) => void;
    resetSmartSearch: () => void;
} {
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<NormalizedSmartSearchResult | null>(
        null,
    );
    const [owned, setOwned] = useState<SmartSearchFilters>({});

    const workingFiltersRef = useRef(currentFilters);
    const onApplyFiltersRef = useRef(onApplyFilters);
    const ownedRef = useRef<SmartSearchFilters>({});
    const pendingApplyRef = useRef(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const requestSeqRef = useRef(0);
    const inFlightPromptRef = useRef<string | null>(null);
    const lastSuccessfulPromptRef = useRef<string | null>(null);
    const cooldownUntilRef = useRef(0);
    const cacheRef = useRef(
        new SmartSearchInterpretationCache(SMART_SEARCH_CACHE_LIMIT),
    );

    onApplyFiltersRef.current = onApplyFilters;

    const applyDirectoryFilters = (next: EmployeeFilters) => {
        if (employeeDirectoryFiltersEqual(workingFiltersRef.current, next)) {
            pendingApplyRef.current = false;
            workingFiltersRef.current = next;

            return;
        }

        pendingApplyRef.current = true;
        workingFiltersRef.current = next;
        onApplyFiltersRef.current(next);
    };

    const cancelDebounce = () => {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
            debounceRef.current = null;
        }
    };

    const abortInFlight = () => {
        abortRef.current?.abort();
        abortRef.current = null;
        inFlightPromptRef.current = null;
    };

    const invalidateInFlight = () => {
        cancelDebounce();
        abortInFlight();
        requestSeqRef.current += 1;
        setLoading(false);
    };

    const setOwnedFilters = (next: SmartSearchFilters) => {
        ownedRef.current = next;
        setOwned(next);
    };

    const applyInterpretation = (
        interpretation: NormalizedSmartSearchResult,
    ) => {
        const { filters, owned: nextOwned } = replaceSmartSearchOwnedFilters(
            workingFiltersRef.current,
            ownedRef.current,
            interpretation.filters,
        );

        setOwnedFilters(nextOwned);
        setResult(interpretation);
        setError(null);

        if (
            !hasApplyableSmartSearchFilters(interpretation.filters) &&
            employeeDirectoryFiltersEqual(workingFiltersRef.current, filters)
        ) {
            pendingApplyRef.current = false;
            workingFiltersRef.current = filters;

            return;
        }

        applyDirectoryFilters(filters);
    };

    const clearSmartSearchOwnedFilters = () => {
        const { filters, owned: nextOwned } = replaceSmartSearchOwnedFilters(
            workingFiltersRef.current,
            ownedRef.current,
            {},
        );

        setOwnedFilters(nextOwned);
        lastSuccessfulPromptRef.current = '';
        setResult(null);
        setError(null);
        setLoading(false);
        applyDirectoryFilters(filters);
    };

    const execute = async (rawPrompt: string) => {
        const normalized = normalizeSmartSearchPrompt(rawPrompt);

        if (normalized === '') {
            cancelDebounce();
            abortInFlight();
            requestSeqRef.current += 1;
            clearSmartSearchOwnedFilters();

            return;
        }

        if (!isSmartSearchPromptReady(normalized)) {
            cancelDebounce();
            abortInFlight();
            requestSeqRef.current += 1;
            clearSmartSearchOwnedFilters();

            return;
        }

        if (Date.now() < cooldownUntilRef.current) {
            return;
        }

        if (inFlightPromptRef.current === normalized) {
            return;
        }

        const cached = cacheRef.current.get(normalized);

        if (cached !== undefined) {
            invalidateInFlight();
            lastSuccessfulPromptRef.current = normalized;
            applyInterpretation(cached);

            return;
        }

        if (lastSuccessfulPromptRef.current === normalized) {
            invalidateInFlight();

            return;
        }

        cancelDebounce();
        abortInFlight();

        const seq = ++requestSeqRef.current;
        const controller = new AbortController();
        abortRef.current = controller;
        inFlightPromptRef.current = normalized;
        setLoading(true);
        setError(null);

        try {
            const interpretation = await interpretEmployeeSmartSearch(
                normalized,
                controller.signal,
            );

            if (seq !== requestSeqRef.current) {
                return;
            }

            cacheRef.current.set(normalized, interpretation);
            lastSuccessfulPromptRef.current = normalized;
            applyInterpretation(interpretation);
        } catch (caught) {
            if (
                caught instanceof EmployeeSmartSearchAbortedError ||
                seq !== requestSeqRef.current
            ) {
                return;
            }

            if (
                caught instanceof EmployeeSmartSearchRequestError &&
                caught.status === 429 &&
                caught.retryAfterSeconds !== null
            ) {
                cooldownUntilRef.current =
                    Date.now() + caught.retryAfterSeconds * 1000;
            }

            setError(
                caught instanceof Error
                    ? caught.message
                    : smartSearchErrorMessage(0),
            );
        } finally {
            if (seq === requestSeqRef.current) {
                setLoading(false);
                inFlightPromptRef.current = null;
                abortRef.current = null;
            }
        }
    };

    const onPromptChange = (value: string) => {
        setPrompt(value);

        const normalized = normalizeSmartSearchPrompt(value);

        if (normalized === '' || !isSmartSearchPromptReady(normalized)) {
            cancelDebounce();
            abortInFlight();
            requestSeqRef.current += 1;
            clearSmartSearchOwnedFilters();

            return;
        }

        if (Date.now() < cooldownUntilRef.current) {
            cancelDebounce();

            return;
        }

        cancelDebounce();
        debounceRef.current = setTimeout(() => {
            debounceRef.current = null;
            void execute(value);
        }, debounceMs);
    };

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (Date.now() < cooldownUntilRef.current) {
            cancelDebounce();

            return;
        }

        cancelDebounce();
        void execute(prompt);
    };

    const onManualFiltersChange = (next: EmployeeFilters) => {
        workingFiltersRef.current = next;
        pendingApplyRef.current = true;
        setOwnedFilters(reconcileSmartSearchOwnership(next, ownedRef.current));
        onApplyFiltersRef.current(next);
    };

    const resetSmartSearch = () => {
        cancelDebounce();
        abortInFlight();
        requestSeqRef.current += 1;
        setPrompt('');
        setOwnedFilters({});
        lastSuccessfulPromptRef.current = '';
        pendingApplyRef.current = false;
        setResult(null);
        setError(null);
        setLoading(false);
    };

    useEffect(() => {
        const reconciled = reconcileServerWorkingFilters(
            workingFiltersRef.current,
            currentFilters,
            pendingApplyRef.current,
        );

        workingFiltersRef.current = reconciled.working;
        pendingApplyRef.current = reconciled.pendingApply;

        if (reconciled.adoptServer) {
            setOwnedFilters(
                reconcileSmartSearchOwnership(
                    reconciled.working,
                    ownedRef.current,
                ),
            );
        }
    }, [currentFilters]);

    useEffect(() => {
        return () => {
            cancelDebounce();
            abortInFlight();
            requestSeqRef.current += 1;
        };
    }, []);

    return {
        prompt,
        loading,
        error,
        result,
        owned,
        workingFilters: workingFiltersRef.current,
        onPromptChange,
        onSubmit,
        onManualFiltersChange,
        resetSmartSearch,
    };
}
