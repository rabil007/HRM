import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { storeTimesheet } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import UpdateCrewTimesheetFinancialsController from '@/actions/App/Http/Controllers/Payroll/UpdateCrewTimesheetFinancialsController';
import { createCrewTimesheetFinancialAutosaveCoordinator } from '../lib/crew-timesheet-financial-autosave-coordinator';
import type {
    CrewTimesheetFinancialAutosaveCoordinator,
    FinancialAutosaveTimesheet,
} from '../lib/crew-timesheet-financial-autosave-coordinator';
import type { CrewPayrollRow, CrewTimesheetDraft } from '../types';
import { buildCrewTimesheetDraft } from '../types';

const AUTOSAVE_DEBOUNCE_MS = 800;

function draftFromFinancialTimesheet(
    timesheet: FinancialAutosaveTimesheet,
): CrewTimesheetDraft {
    return {
        unpaid_leave_days:
            timesheet?.unpaid_leave_days === null ||
            timesheet?.unpaid_leave_days === undefined
                ? ''
                : String(timesheet.unpaid_leave_days),
        overtime_hours:
            timesheet?.overtime_hours === null ||
            timesheet?.overtime_hours === undefined
                ? ''
                : String(timesheet.overtime_hours),
    };
}

function buildChangedFinancialPayload(
    current: CrewTimesheetDraft,
    initialTimesheet: FinancialAutosaveTimesheet,
): Record<string, number | string | null> {
    const payload: Record<string, number | string | null> = {};
    const initialDraft = draftFromFinancialTimesheet(initialTimesheet);

    if (current.overtime_hours !== initialDraft.overtime_hours) {
        payload.overtime_hours =
            current.overtime_hours === '' ? 0 : Number(current.overtime_hours);
    }

    if (current.unpaid_leave_days !== initialDraft.unpaid_leave_days) {
        payload.unpaid_leave_days =
            current.unpaid_leave_days === ''
                ? null
                : Number(current.unpaid_leave_days);
    }

    return payload;
}

export function useCrewTimesheetFinancialAutosave({
    periodId,
    resolveTimesheet,
}: {
    periodId: number;
    resolveTimesheet: (
        employeeId: number,
    ) => CrewPayrollRow['timesheet'] | null | undefined;
}) {
    const [crewTimesheetDrafts, setCrewTimesheetDrafts] = useState<
        Record<number, CrewTimesheetDraft>
    >({});
    const [savingTimesheetEmployeeIds, setSavingTimesheetEmployeeIds] =
        useState<number[]>([]);
    const [financialAutosaveErrors, setFinancialAutosaveErrors] = useState<
        Record<number, string>
    >({});

    const crewTimesheetDraftsRef = useRef(crewTimesheetDrafts);
    const financialAutosaveErrorsRef = useRef(financialAutosaveErrors);
    const savingTimesheetEmployeeIdsRef = useRef(savingTimesheetEmployeeIds);
    const crewSaveTimersRef = useRef<
        Record<number, ReturnType<typeof setTimeout>>
    >({});
    const isClearingTimesheetsRef = useRef(false);
    const resolveTimesheetRef = useRef(resolveTimesheet);
    const periodIdRef = useRef(periodId);
    const errorGenerationRef = useRef<Record<number, number>>({});
    const coordinatorRef =
        useRef<CrewTimesheetFinancialAutosaveCoordinator | null>(null);

    useEffect(() => {
        crewTimesheetDraftsRef.current = crewTimesheetDrafts;
    }, [crewTimesheetDrafts]);

    useEffect(() => {
        savingTimesheetEmployeeIdsRef.current = savingTimesheetEmployeeIds;
    }, [savingTimesheetEmployeeIds]);

    useEffect(() => {
        resolveTimesheetRef.current = resolveTimesheet;
    }, [resolveTimesheet]);

    useEffect(() => {
        periodIdRef.current = periodId;
    }, [periodId]);

    const setFinancialSaveError = useCallback(
        (employeeId: number, message: string | null, generation: number) => {
            const currentGeneration =
                errorGenerationRef.current[employeeId] ?? 0;

            if (message === null) {
                if (generation < currentGeneration) {
                    return;
                }

                errorGenerationRef.current[employeeId] = generation;

                if (!(employeeId in financialAutosaveErrorsRef.current)) {
                    return;
                }

                const next = { ...financialAutosaveErrorsRef.current };
                delete next[employeeId];
                financialAutosaveErrorsRef.current = next;
                setFinancialAutosaveErrors(next);

                return;
            }

            if (generation < currentGeneration) {
                return;
            }

            errorGenerationRef.current[employeeId] = generation;
            const next = {
                ...financialAutosaveErrorsRef.current,
                [employeeId]: message,
            };
            financialAutosaveErrorsRef.current = next;
            setFinancialAutosaveErrors(next);
        },
        [],
    );

    const getCoordinator =
        useCallback((): CrewTimesheetFinancialAutosaveCoordinator => {
            if (coordinatorRef.current) {
                return coordinatorRef.current;
            }

            coordinatorRef.current =
                createCrewTimesheetFinancialAutosaveCoordinator({
                    getDraft: (employeeId) =>
                        crewTimesheetDraftsRef.current[employeeId],
                    getInitialTimesheet: (employeeId) =>
                        resolveTimesheetRef.current(employeeId) ?? null,
                    buildPayload: buildChangedFinancialPayload,
                    isInvalidated: () => isClearingTimesheetsRef.current,
                    onSavingChange: (employeeId, saving) => {
                        setSavingTimesheetEmployeeIds((previous) => {
                            const isPresent = previous.includes(employeeId);

                            if (saving && !isPresent) {
                                const next = [...previous, employeeId];
                                savingTimesheetEmployeeIdsRef.current = next;

                                return next;
                            }

                            if (!saving && isPresent) {
                                const next = previous.filter(
                                    (id) => id !== employeeId,
                                );
                                savingTimesheetEmployeeIdsRef.current = next;

                                return next;
                            }

                            return previous;
                        });
                    },
                    onErrorChange: (employeeId, message, generation) => {
                        setFinancialSaveError(employeeId, message, generation);
                    },
                    onClearDraftIfUnchanged: (
                        employeeId,
                        submittedDraft,
                        _generation,
                    ) => {
                        void _generation;

                        if (crewSaveTimersRef.current[employeeId]) {
                            return;
                        }

                        setCrewTimesheetDrafts((prev) => {
                            const draft = prev[employeeId];

                            if (!draft) {
                                return prev;
                            }

                            if (
                                draft.unpaid_leave_days !==
                                    submittedDraft.unpaid_leave_days ||
                                draft.overtime_hours !==
                                    submittedDraft.overtime_hours
                            ) {
                                return prev;
                            }

                            const next = { ...prev };
                            delete next[employeeId];
                            crewTimesheetDraftsRef.current = next;

                            return next;
                        });
                    },
                    performSave: ({
                        employeeId,
                        initialTimesheet,
                        payload,
                    }) => {
                        if (isClearingTimesheetsRef.current) {
                            return Promise.resolve();
                        }

                        const timesheetId =
                            resolveTimesheetRef.current(employeeId)?.id ??
                            initialTimesheet?.id ??
                            null;
                        const currentPeriodId = periodIdRef.current;

                        return new Promise<void>((resolve, reject) => {
                            let settled = false;

                            const settleError = (message: string): void => {
                                if (settled) {
                                    return;
                                }

                                settled = true;
                                reject(new Error(message));
                            };

                            const visitOptions = {
                                preserveScroll: true,
                                preserveState: true,
                                only: ['rows'] as string[],
                                onFinish: () => {
                                    if (!settled) {
                                        settleError(
                                            'Financial autosave failed.',
                                        );
                                    }
                                },
                                onSuccess: () => {
                                    settled = true;
                                    resolve();
                                },
                                onError: (errors: Record<string, string>) => {
                                    settleError(
                                        Object.values(errors)[0] ??
                                            'Financial autosave failed.',
                                    );
                                },
                            };

                            if (timesheetId !== null) {
                                router.patch(
                                    UpdateCrewTimesheetFinancialsController.url(
                                        {
                                            payrollPeriod: currentPeriodId,
                                            timesheet: timesheetId,
                                        },
                                    ),
                                    payload,
                                    visitOptions,
                                );

                                return;
                            }

                            router.post(
                                storeTimesheet.url(currentPeriodId),
                                {
                                    period_id: currentPeriodId,
                                    employee_id: employeeId,
                                    ...payload,
                                },
                                visitOptions,
                            );
                        });
                    },
                });

            return coordinatorRef.current;
        }, [setFinancialSaveError]);

    const clearFinancialAutosaveError = useCallback(
        (employeeId: number) => {
            const generation = Math.max(
                errorGenerationRef.current[employeeId] ?? 0,
                0,
            );
            setFinancialSaveError(employeeId, null, generation);
        },
        [setFinancialSaveError],
    );

    useEffect(() => {
        const timers = crewSaveTimersRef.current;

        return () => {
            Object.values(timers).forEach((timer) => {
                clearTimeout(timer);
            });
        };
    }, []);

    const enqueueFinancialSave = useCallback(
        (
            employeeId: number,
            initialTimesheet: CrewPayrollRow['timesheet'],
        ): Promise<void> =>
            getCoordinator().enqueueFinancialSave(
                employeeId,
                initialTimesheet ?? null,
            ),
        [getCoordinator],
    );

    const scheduleFinancialAutosave = useCallback(
        (employeeId: number, initialTimesheet: CrewPayrollRow['timesheet']) => {
            if (isClearingTimesheetsRef.current) {
                return;
            }

            const existingTimer = crewSaveTimersRef.current[employeeId];

            if (existingTimer) {
                clearTimeout(existingTimer);
            }

            crewSaveTimersRef.current[employeeId] = setTimeout(() => {
                delete crewSaveTimersRef.current[employeeId];
                void enqueueFinancialSave(employeeId, initialTimesheet).catch(
                    () => {
                        // Draft preserved; generation-aware error already recorded.
                    },
                );
            }, AUTOSAVE_DEBOUNCE_MS);
        },
        [enqueueFinancialSave],
    );

    const handleCrewTimesheetChange = useCallback(
        (
            employeeId: number,
            field: keyof CrewTimesheetDraft,
            val: string,
            initialTimesheet: CrewPayrollRow['timesheet'],
        ) => {
            if (isClearingTimesheetsRef.current) {
                return;
            }

            clearFinancialAutosaveError(employeeId);

            setCrewTimesheetDrafts((prev) => {
                const existing =
                    prev[employeeId] ??
                    buildCrewTimesheetDraft(initialTimesheet);

                const next = {
                    ...prev,
                    [employeeId]: {
                        ...existing,
                        [field]: val,
                    },
                };

                crewTimesheetDraftsRef.current = next;

                return next;
            });

            scheduleFinancialAutosave(employeeId, initialTimesheet);
        },
        [clearFinancialAutosaveError, scheduleFinancialAutosave],
    );

    const retryFinancialAutosave = useCallback(
        (employeeId: number, initialTimesheet: CrewPayrollRow['timesheet']) => {
            if (isClearingTimesheetsRef.current) {
                return;
            }

            const existingTimer = crewSaveTimersRef.current[employeeId];

            if (existingTimer) {
                clearTimeout(existingTimer);
                delete crewSaveTimersRef.current[employeeId];
            }

            clearFinancialAutosaveError(employeeId);

            void enqueueFinancialSave(employeeId, initialTimesheet).catch(
                () => {
                    // Draft preserved; generation-aware error already recorded.
                },
            );
        },
        [clearFinancialAutosaveError, enqueueFinancialSave],
    );

    const flushPendingFinancialSave = useCallback(
        async (
            employeeId: number,
            initialTimesheet: CrewPayrollRow['timesheet'],
        ): Promise<void> => {
            const coordinator = getCoordinator();
            const existingTimer = crewSaveTimersRef.current[employeeId];

            if (existingTimer) {
                clearTimeout(existingTimer);
                delete crewSaveTimersRef.current[employeeId];
            }

            const hasDraft = Boolean(
                crewTimesheetDraftsRef.current[employeeId],
            );

            if (
                existingTimer ||
                hasDraft ||
                coordinator.isEmployeeBusy(employeeId)
            ) {
                await enqueueFinancialSave(employeeId, initialTimesheet);
            }

            await coordinator.waitUntilIdle(employeeId);

            const pendingError =
                financialAutosaveErrorsRef.current[employeeId] ?? null;

            if (pendingError) {
                throw new Error(pendingError);
            }
        },
        [enqueueFinancialSave, getCoordinator],
    );

    const cancelPendingCrewTimesheetAutosaves = useCallback(() => {
        Object.values(crewSaveTimersRef.current).forEach((timer) => {
            clearTimeout(timer);
        });
        crewSaveTimersRef.current = {};
        crewTimesheetDraftsRef.current = {};
        setCrewTimesheetDrafts({});
        financialAutosaveErrorsRef.current = {};
        errorGenerationRef.current = {};
        setFinancialAutosaveErrors({});
    }, []);

    const beginClearTimesheets = useCallback(async () => {
        cancelPendingCrewTimesheetAutosaves();
        isClearingTimesheetsRef.current = true;
        const coordinator = getCoordinator();
        coordinator.invalidate();
        await coordinator.waitUntilIdle();
    }, [cancelPendingCrewTimesheetAutosaves, getCoordinator]);

    const endClearTimesheets = useCallback(() => {
        isClearingTimesheetsRef.current = false;
    }, []);

    const clearEmployeeDraft = useCallback((employeeId: number) => {
        setCrewTimesheetDrafts((prev) => {
            if (!prev[employeeId]) {
                return prev;
            }

            const next = { ...prev };
            delete next[employeeId];
            crewTimesheetDraftsRef.current = next;

            return next;
        });
    }, []);

    return {
        crewTimesheetDrafts,
        savingTimesheetEmployeeIds,
        financialAutosaveErrors,
        handleCrewTimesheetChange,
        retryFinancialAutosave,
        flushPendingFinancialSave,
        cancelPendingCrewTimesheetAutosaves,
        beginClearTimesheets,
        endClearTimesheets,
        clearEmployeeDraft,
    };
}
