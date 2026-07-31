import type { CrewTimesheetDraft } from '../types';

export type FinancialAutosaveTimesheet = {
    id: number;
    overtime_hours?: string | number | null;
    unpaid_leave_days?: string | number | null;
    additional_amount?: string | number | null;
    deduction_amount?: string | number | null;
    remarks?: string | null;
} | null;

export type FinancialSaveState = {
    generation: number;
    promise: Promise<void> | null;
    queued: boolean;
};

export type PerformFinancialSaveArgs = {
    employeeId: number;
    generation: number;
    draft: CrewTimesheetDraft;
    initialTimesheet: FinancialAutosaveTimesheet;
    payload: Record<string, number | string | null>;
};

export type CrewTimesheetFinancialAutosaveCoordinatorOptions = {
    getDraft: (employeeId: number) => CrewTimesheetDraft | undefined;
    getInitialTimesheet: (
        employeeId: number,
    ) => FinancialAutosaveTimesheet | undefined;
    buildPayload: (
        draft: CrewTimesheetDraft,
        initialTimesheet: FinancialAutosaveTimesheet,
    ) => Record<string, number | string | null>;
    performSave: (args: PerformFinancialSaveArgs) => Promise<void>;
    onSavingChange: (employeeId: number, saving: boolean) => void;
    /**
     * Apply an error for a specific generation. Pass null to clear.
     * Implementations must ignore stale generations.
     */
    onErrorChange: (
        employeeId: number,
        message: string | null,
        generation: number,
    ) => void;
    /**
     * Clear the draft only when it still matches the submitted values
     * and the generation is still current.
     */
    onClearDraftIfUnchanged: (
        employeeId: number,
        submittedDraft: CrewTimesheetDraft,
        generation: number,
    ) => void;
    isInvalidated: () => boolean;
};

type EmployeeCoordinatorState = {
    generation: number;
    activeGeneration: number | null;
    promise: Promise<void> | null;
    queued: boolean;
    chainPromise: Promise<void> | null;
    latestInitialTimesheet: FinancialAutosaveTimesheet;
};

function draftsEqual(
    left: CrewTimesheetDraft,
    right: CrewTimesheetDraft,
): boolean {
    return (
        left.overtime_hours === right.overtime_hours &&
        left.unpaid_leave_days === right.unpaid_leave_days
    );
}

export function createCrewTimesheetFinancialAutosaveCoordinator(
    options: CrewTimesheetFinancialAutosaveCoordinatorOptions,
) {
    const states = new Map<number, EmployeeCoordinatorState>();
    let invalidationToken = 0;

    const getState = (employeeId: number): EmployeeCoordinatorState => {
        const existing = states.get(employeeId);

        if (existing) {
            return existing;
        }

        const created: EmployeeCoordinatorState = {
            generation: 0,
            activeGeneration: null,
            promise: null,
            queued: false,
            chainPromise: null,
            latestInitialTimesheet: null,
        };

        states.set(employeeId, created);

        return created;
    };

    const isEmployeeBusy = (employeeId: number): boolean => {
        const state = states.get(employeeId);

        return Boolean(state?.promise || state?.queued || state?.chainPromise);
    };

    const snapshot = (employeeId: number): FinancialSaveState | null => {
        const state = states.get(employeeId);

        if (!state) {
            return null;
        }

        return {
            generation: state.generation,
            promise: state.promise,
            queued: state.queued,
        };
    };

    const invalidate = (): void => {
        invalidationToken += 1;

        for (const state of states.values()) {
            state.queued = false;
        }
    };

    const waitUntilIdle = async (employeeId?: number): Promise<void> => {
        if (employeeId !== undefined) {
            const state = states.get(employeeId);

            if (state?.chainPromise) {
                await state.chainPromise.catch(() => undefined);
            }

            return;
        }

        const pending = [...states.values()]
            .map((state) => state.chainPromise)
            .filter((promise): promise is Promise<void> => promise !== null);

        await Promise.all(
            pending.map((promise) => promise.catch(() => undefined)),
        );
    };

    const drainEmployee = async (employeeId: number): Promise<void> => {
        const state = getState(employeeId);
        const startedToken = invalidationToken;

        try {
            while (
                !options.isInvalidated() &&
                startedToken === invalidationToken
            ) {
                state.queued = false;

                const draft = options.getDraft(employeeId);

                if (!draft) {
                    break;
                }

                const initialTimesheet =
                    options.getInitialTimesheet(employeeId) ??
                    state.latestInitialTimesheet;

                state.latestInitialTimesheet = initialTimesheet ?? null;

                const payload = options.buildPayload(
                    draft,
                    state.latestInitialTimesheet,
                );

                if (Object.keys(payload).length === 0) {
                    const generation = state.generation;
                    options.onClearDraftIfUnchanged(
                        employeeId,
                        draft,
                        generation,
                    );
                    break;
                }

                const generation = state.generation + 1;
                state.generation = generation;
                state.activeGeneration = generation;

                const submittedDraft: CrewTimesheetDraft = { ...draft };

                const requestPromise = options.performSave({
                    employeeId,
                    generation,
                    draft: submittedDraft,
                    initialTimesheet: state.latestInitialTimesheet,
                    payload,
                });

                state.promise = requestPromise;

                let failed = false;
                let failureMessage = 'Financial autosave failed.';

                try {
                    await requestPromise;

                    if (
                        generation === state.generation &&
                        !options.isInvalidated() &&
                        startedToken === invalidationToken
                    ) {
                        options.onErrorChange(employeeId, null, generation);

                        if (!state.queued) {
                            options.onClearDraftIfUnchanged(
                                employeeId,
                                submittedDraft,
                                generation,
                            );
                        }
                    }
                } catch (error) {
                    failed = true;
                    failureMessage =
                        error instanceof Error
                            ? error.message
                            : 'Financial autosave failed.';

                    if (
                        generation === state.generation &&
                        !state.queued &&
                        !options.isInvalidated() &&
                        startedToken === invalidationToken
                    ) {
                        options.onErrorChange(
                            employeeId,
                            failureMessage,
                            generation,
                        );
                    }
                } finally {
                    if (state.activeGeneration === generation) {
                        state.activeGeneration = null;
                    }

                    if (state.promise === requestPromise) {
                        state.promise = null;
                    }
                }

                if (
                    options.isInvalidated() ||
                    startedToken !== invalidationToken
                ) {
                    break;
                }

                const latestDraft = options.getDraft(employeeId);

                if (state.queued) {
                    continue;
                }

                if (latestDraft && !draftsEqual(latestDraft, submittedDraft)) {
                    continue;
                }

                if (failed) {
                    throw new Error(failureMessage);
                }

                break;
            }
        } finally {
            // Saving UI is cleared by enqueueFinancialSave when the chain settles.
        }
    };

    const enqueueFinancialSave = (
        employeeId: number,
        initialTimesheet: FinancialAutosaveTimesheet,
    ): Promise<void> => {
        if (options.isInvalidated()) {
            return Promise.resolve();
        }

        const state = getState(employeeId);
        state.latestInitialTimesheet = initialTimesheet;

        if (state.chainPromise) {
            state.queued = true;
            options.onSavingChange(employeeId, true);

            return state.chainPromise;
        }

        options.onSavingChange(employeeId, true);

        const chainPromise = drainEmployee(employeeId).finally(() => {
            if (state.chainPromise === chainPromise) {
                state.chainPromise = null;
            }

            if (!state.promise && !state.queued && !state.chainPromise) {
                options.onSavingChange(employeeId, false);
            }
        });

        state.chainPromise = chainPromise;

        return chainPromise;
    };

    return {
        enqueueFinancialSave,
        invalidate,
        waitUntilIdle,
        isEmployeeBusy,
        snapshot,
        getGeneration: (employeeId: number): number =>
            getState(employeeId).generation,
    };
}

export type CrewTimesheetFinancialAutosaveCoordinator = ReturnType<
    typeof createCrewTimesheetFinancialAutosaveCoordinator
>;
