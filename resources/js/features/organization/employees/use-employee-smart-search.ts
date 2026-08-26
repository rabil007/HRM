import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { EmployeeFilters } from '@/features/organization/employees/components/employee-filters-sheet';
import {
    EmployeeSmartSearchAbortedError,
    interpretEmployeeSmartSearch,
} from '@/features/organization/employees/interpret-employee-smart-search';
import {
    SMART_SEARCH_CACHE_LIMIT,
    SMART_SEARCH_DEBOUNCE_MS,
    employeeDirectoryFiltersEqual,
    isSmartSearchPromptReady,
    normalizeSmartSearchPrompt,
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
    onPromptChange: (value: string) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
} {
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<NormalizedSmartSearchResult | null>(
        null,
    );

    const currentFiltersRef = useRef(currentFilters);
    const onApplyFiltersRef = useRef(onApplyFilters);
    const ownedRef = useRef<SmartSearchFilters>({});
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const requestSeqRef = useRef(0);
    const inFlightPromptRef = useRef<string | null>(null);
    const lastSuccessfulPromptRef = useRef<string | null>(null);
    const cacheRef = useRef(
        new SmartSearchInterpretationCache(SMART_SEARCH_CACHE_LIMIT),
    );

    currentFiltersRef.current = currentFilters;
    onApplyFiltersRef.current = onApplyFilters;

    const applyDirectoryFilters = (next: EmployeeFilters) => {
        if (employeeDirectoryFiltersEqual(currentFiltersRef.current, next)) {
            return;
        }

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

    const applyInterpretation = (
        interpretation: NormalizedSmartSearchResult,
    ) => {
        const { filters, owned } = replaceSmartSearchOwnedFilters(
            currentFiltersRef.current,
            ownedRef.current,
            interpretation.filters,
        );

        ownedRef.current = owned;
        setResult(interpretation);
        setError(null);
        applyDirectoryFilters(filters);
    };

    const clearSmartSearchOwnedFilters = () => {
        const { filters, owned } = replaceSmartSearchOwnedFilters(
            currentFiltersRef.current,
            ownedRef.current,
            {},
        );

        ownedRef.current = owned;
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

        if (normalizeSmartSearchPrompt(value) === '') {
            cancelDebounce();
            abortInFlight();
            requestSeqRef.current += 1;
            clearSmartSearchOwnedFilters();

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
        cancelDebounce();
        void execute(prompt);
    };

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
        onPromptChange,
        onSubmit,
    };
}
