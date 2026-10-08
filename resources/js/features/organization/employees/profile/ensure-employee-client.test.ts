import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    createDedupedEnsureEmployee,
    EnsureEmployeeRequestError,
} from './ensure-employee-client.ts';

describe('deduped ensure employee client', () => {
    it('reuses the same in-flight request for concurrent calls', async () => {
        let calls = 0;

        const deduper = createDedupedEnsureEmployee(async () => {
            calls += 1;

            return {
                id: 99,
                name: 'Captain Ahmed',
                employee_no: 'DRAFT-ABCDEFGH',
            };
        });

        const [first, second] = await Promise.all([
            deduper.ensure(null),
            deduper.ensure(null),
        ]);

        assert.equal(calls, 1);
        assert.equal(first.id, 99);
        assert.equal(second.id, 99);
    });

    it('reuses cached employee id on subsequent calls', async () => {
        let calls = 0;

        const deduper = createDedupedEnsureEmployee(async () => {
            calls += 1;

            return {
                id: 100,
                name: 'Captain Ahmed',
                employee_no: 'DRAFT-ABCDEFGH',
            };
        });

        await deduper.ensure(null);
        await deduper.ensure(null);

        assert.equal(calls, 1);
    });

    it('clears in-flight state after failure so retries can run again', async () => {
        let calls = 0;

        const deduper = createDedupedEnsureEmployee(async () => {
            calls += 1;

            if (calls === 1) {
                throw new EnsureEmployeeRequestError('ensure_failed');
            }

            return {
                id: 101,
                name: 'Captain Ahmed',
                employee_no: 'DRAFT-ABCDEFGH',
            };
        });

        await assert.rejects(() => deduper.ensure(null));
        const ensured = await deduper.ensure(null);

        assert.equal(calls, 2);
        assert.equal(ensured.id, 101);
    });

    it('returns an existing resolved employee id without creating another record', async () => {
        let calls = 0;

        const deduper = createDedupedEnsureEmployee(async () => {
            calls += 1;

            return {
                id: 102,
                name: 'Captain Ahmed',
                employee_no: 'DRAFT-ABCDEFGH',
            };
        });

        await deduper.ensure(null);
        const reused = await deduper.ensure(102);

        assert.equal(calls, 1);
        assert.equal(reused.id, 102);
    });
});
