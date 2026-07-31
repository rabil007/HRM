import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewTimesheetDraft } from '../types.ts';
import { createCrewTimesheetFinancialAutosaveCoordinator } from './crew-timesheet-financial-autosave-coordinator.ts';
import type { PerformFinancialSaveArgs } from './crew-timesheet-financial-autosave-coordinator.ts';

type Harness = {
    drafts: Record<number, CrewTimesheetDraft>;
    errors: Record<number, string>;
    errorGenerations: Record<number, number>;
    saving: Set<number>;
    activeCount: number;
    maxActive: number;
    calls: PerformFinancialSaveArgs[];
    controllers: Array<{
        resolve: () => void;
        reject: (error: Error) => void;
    }>;
    invalidated: boolean;
    coordinator: ReturnType<
        typeof createCrewTimesheetFinancialAutosaveCoordinator
    >;
};

function createHarness(): Harness {
    const harness: Harness = {
        drafts: {},
        errors: {},
        errorGenerations: {},
        saving: new Set(),
        activeCount: 0,
        maxActive: 0,
        calls: [],
        controllers: [],
        invalidated: false,
        coordinator: null as unknown as Harness['coordinator'],
    };

    harness.coordinator = createCrewTimesheetFinancialAutosaveCoordinator({
        getDraft: (employeeId) => harness.drafts[employeeId],
        getInitialTimesheet: (employeeId) =>
            employeeId === 1
                ? { id: 10, overtime_hours: '0' }
                : { id: 20, overtime_hours: '0' },
        buildPayload: (draft, initial) => {
            const payload: Record<string, number | string | null> = {};
            const initialOvertime = String(initial?.overtime_hours ?? '');

            if (draft.overtime_hours !== initialOvertime) {
                payload.overtime_hours =
                    draft.overtime_hours === ''
                        ? 0
                        : Number(draft.overtime_hours);
            }

            return payload;
        },
        isInvalidated: () => harness.invalidated,
        onSavingChange: (employeeId, saving) => {
            if (saving) {
                harness.saving.add(employeeId);
            } else {
                harness.saving.delete(employeeId);
            }
        },
        onErrorChange: (employeeId, message, generation) => {
            const current = harness.errorGenerations[employeeId] ?? 0;

            if (generation < current) {
                return;
            }

            harness.errorGenerations[employeeId] = generation;

            if (message === null) {
                delete harness.errors[employeeId];
            } else {
                harness.errors[employeeId] = message;
            }
        },
        onClearDraftIfUnchanged: (employeeId, submittedDraft, generation) => {
            void generation;
            const draft = harness.drafts[employeeId];

            if (
                draft &&
                draft.overtime_hours === submittedDraft.overtime_hours &&
                draft.unpaid_leave_days === submittedDraft.unpaid_leave_days
            ) {
                delete harness.drafts[employeeId];
            }
        },
        performSave: (args) => {
            harness.calls.push(args);
            harness.activeCount += 1;
            harness.maxActive = Math.max(
                harness.maxActive,
                harness.activeCount,
            );

            return new Promise<void>((resolve, reject) => {
                harness.controllers.push({
                    resolve: () => {
                        harness.activeCount -= 1;
                        resolve();
                    },
                    reject: (error) => {
                        harness.activeCount -= 1;
                        reject(error);
                    },
                });
            });
        },
    });

    return harness;
}

describe('crew timesheet financial autosave coordinator', () => {
    it('serializes two quick edits for the same employee and sends the latest draft', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '2', unpaid_leave_days: '' };

        const first = harness.coordinator.enqueueFinancialSave(1, {
            id: 10,
            overtime_hours: '1',
        });

        await Promise.resolve();
        assert.equal(harness.calls.length, 1);
        assert.equal(harness.saving.has(1), true);

        harness.drafts[1] = { overtime_hours: '9', unpaid_leave_days: '' };
        const second = harness.coordinator.enqueueFinancialSave(1, {
            id: 10,
            overtime_hours: '1',
        });

        assert.equal(harness.calls.length, 1);
        assert.equal(harness.maxActive, 1);
        assert.equal(harness.coordinator.snapshot(1)?.queued, true);

        harness.controllers[0]?.resolve();
        await Promise.resolve();
        await Promise.resolve();

        assert.equal(harness.calls.length, 2);
        assert.equal(harness.calls[1]?.payload.overtime_hours, 9);
        assert.equal(harness.maxActive, 1);

        harness.controllers[1]?.resolve();
        await Promise.all([first, second]);

        assert.equal(harness.saving.has(1), false);
        assert.equal(harness.drafts[1], undefined);
        assert.equal(harness.errors[1], undefined);
    });

    it('does not let an older failure overwrite a newer success', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '3', unpaid_leave_days: '' };

        const first = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();

        harness.drafts[1] = { overtime_hours: '4', unpaid_leave_days: '' };
        const second = harness.coordinator.enqueueFinancialSave(1, { id: 10 });

        // Fail the first request; queued second should still run.
        harness.controllers[0]?.reject(new Error('stale failure'));
        await Promise.resolve();
        await Promise.resolve();

        assert.equal(harness.calls.length, 2);
        assert.equal(harness.errors[1], undefined);

        harness.controllers[1]?.resolve();
        await Promise.all([first, second]);

        assert.equal(harness.errors[1], undefined);
        assert.equal(harness.drafts[1], undefined);
    });

    it('preserves draft and records error for the latest failed save', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '5', unpaid_leave_days: '' };

        const save = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        harness.controllers[0]?.reject(new Error('server rejected'));

        await assert.rejects(save, /server rejected/);
        assert.equal(harness.errors[1], 'server rejected');
        assert.equal(harness.drafts[1]?.overtime_hours, '5');
        assert.equal(harness.saving.has(1), false);
    });

    it('keeps saving state active while a queued request exists', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };

        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        assert.equal(harness.saving.has(1), true);

        harness.drafts[1] = { overtime_hours: '2', unpaid_leave_days: '' };
        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        assert.equal(harness.saving.has(1), true);

        harness.controllers[0]?.resolve();
        await Promise.resolve();
        await Promise.resolve();
        assert.equal(harness.saving.has(1), true);

        harness.controllers[1]?.resolve();
        await harness.coordinator.waitUntilIdle(1);
        assert.equal(harness.saving.has(1), false);
    });

    it('allows different employees to save concurrently', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };
        harness.drafts[2] = { overtime_hours: '2', unpaid_leave_days: '' };

        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        void harness.coordinator.enqueueFinancialSave(2, { id: 20 });
        await Promise.resolve();

        assert.equal(harness.calls.length, 2);
        assert.equal(harness.maxActive, 2);

        harness.controllers[0]?.resolve();
        harness.controllers[1]?.resolve();
        await harness.coordinator.waitUntilIdle();
    });

    it('waits for active and queued saves before idle', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };

        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        harness.drafts[1] = { overtime_hours: '8', unpaid_leave_days: '' };
        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });

        let idle = false;
        const waiting = harness.coordinator.waitUntilIdle(1).then(() => {
            idle = true;
        });

        await Promise.resolve();
        assert.equal(idle, false);

        harness.controllers[0]?.resolve();
        await Promise.resolve();
        await Promise.resolve();
        assert.equal(idle, false);

        harness.controllers[1]?.resolve();
        await waiting;
        assert.equal(idle, true);
    });

    it('invalidates pending autosaves for clear timesheets', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };

        const save = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();

        harness.drafts[1] = { overtime_hours: '9', unpaid_leave_days: '' };
        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        harness.invalidated = true;
        harness.coordinator.invalidate();

        harness.controllers[0]?.resolve();
        await save.catch(() => undefined);
        await harness.coordinator.waitUntilIdle(1);

        assert.equal(harness.calls.length, 1);
        assert.equal(harness.saving.has(1), false);
    });

    it('does not start a new save after invalidation', async () => {
        const harness = createHarness();
        harness.invalidated = true;
        harness.coordinator.invalidate();
        harness.drafts[1] = { overtime_hours: '3', unpaid_leave_days: '' };

        await harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        assert.equal(harness.calls.length, 0);
    });

    it('does not clear a newer draft when an older request succeeds after a newer edit', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };

        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();

        harness.drafts[1] = { overtime_hours: '7', unpaid_leave_days: '' };
        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });

        harness.controllers[0]?.resolve();
        await Promise.resolve();
        await Promise.resolve();

        // First request succeeded but a newer draft remains until second finishes.
        assert.equal(harness.drafts[1]?.overtime_hours, '7');

        harness.controllers[1]?.resolve();
        await harness.coordinator.waitUntilIdle(1);
        assert.equal(harness.drafts[1], undefined);
    });

    it('retry after failure submits the latest draft and clears the error', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '5', unpaid_leave_days: '' };

        const failed = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        harness.controllers[0]?.reject(new Error('first failed'));
        await assert.rejects(failed, /first failed/);
        assert.equal(harness.errors[1], 'first failed');

        harness.drafts[1] = { overtime_hours: '12', unpaid_leave_days: '' };
        const retry = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();

        assert.equal(harness.calls[1]?.payload.overtime_hours, 12);
        harness.controllers[1]?.resolve();
        await retry;

        assert.equal(harness.errors[1], undefined);
        assert.equal(harness.drafts[1], undefined);
    });

    it('catches background promise rejection without becoming unhandled', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '6', unpaid_leave_days: '' };

        let unhandled = false;
        const onUnhandled = () => {
            unhandled = true;
        };

        process.on('unhandledRejection', onUnhandled);

        try {
            void harness.coordinator
                .enqueueFinancialSave(1, { id: 10 })
                .catch(() => undefined);
            await Promise.resolve();
            harness.controllers[0]?.reject(new Error('background fail'));
            await Promise.resolve();
            await Promise.resolve();

            assert.equal(unhandled, false);
            assert.equal(harness.errors[1], 'background fail');
        } finally {
            process.off('unhandledRejection', onUnhandled);
        }
    });

    it('does not let a stale success clear a newer generation error', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };

        const first = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        harness.controllers[0]?.reject(new Error('gen1 failed'));
        await assert.rejects(first, /gen1 failed/);
        assert.equal(harness.errors[1], 'gen1 failed');
        assert.equal(harness.errorGenerations[1], 1);

        // A newer failed generation is recorded; a stale clear must be ignored.
        harness.errorGenerations[1] = 2;
        harness.errors[1] = 'gen2 failed';

        const applyErrorChange = (
            employeeId: number,
            message: string | null,
            generation: number,
        ): void => {
            const current = harness.errorGenerations[employeeId] ?? 0;

            if (generation < current) {
                return;
            }

            harness.errorGenerations[employeeId] = generation;

            if (message === null) {
                delete harness.errors[employeeId];
            } else {
                harness.errors[employeeId] = message;
            }
        };

        applyErrorChange(1, null, 1);
        assert.equal(harness.errors[1], 'gen2 failed');

        applyErrorChange(1, null, 2);
        assert.equal(harness.errors[1], undefined);
    });

    it('waitUntilIdle supports movement-period flush over queued saves', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '1', unpaid_leave_days: '' };

        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        harness.drafts[1] = { overtime_hours: '3', unpaid_leave_days: '' };
        void harness.coordinator.enqueueFinancialSave(1, { id: 10 });

        const flush = (async () => {
            await harness.coordinator.waitUntilIdle(1);

            if (harness.errors[1]) {
                throw new Error(harness.errors[1]);
            }
        })();

        let flushSettled = false;
        void flush.then(() => {
            flushSettled = true;
        });

        await Promise.resolve();
        assert.equal(flushSettled, false);

        harness.controllers[0]?.resolve();
        await Promise.resolve();
        await Promise.resolve();
        assert.equal(flushSettled, false);

        harness.controllers[1]?.resolve();
        await flush;
        assert.equal(flushSettled, true);
    });

    it('flush rejects when the latest financial save fails', async () => {
        const harness = createHarness();
        harness.drafts[1] = { overtime_hours: '4', unpaid_leave_days: '' };

        const save = harness.coordinator.enqueueFinancialSave(1, { id: 10 });
        await Promise.resolve();
        harness.controllers[0]?.reject(new Error('flush blocked'));
        await assert.rejects(save, /flush blocked/);

        await assert.rejects(async () => {
            await harness.coordinator.waitUntilIdle(1);

            if (harness.errors[1]) {
                throw new Error(harness.errors[1]);
            }
        }, /flush blocked/);
    });
});
