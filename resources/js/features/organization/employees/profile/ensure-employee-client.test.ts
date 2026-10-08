import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    createDedupedEnsureEmployee,
    createEnsureIdempotencyKey,
    EnsureEmployeeRequestError,
    postEnsureEmployee,
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

        const body = {
            name: 'Captain Ahmed',
            employee_profile_template_id: null,
            idempotency_key: 'aaaaaaaaaaaaaaaa',
        };

        const [first, second] = await Promise.all([
            deduper.ensure(null, body),
            deduper.ensure(null, body),
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

        const body = {
            name: 'Captain Ahmed',
            employee_profile_template_id: null,
            idempotency_key: 'bbbbbbbbbbbbbbbb',
        };

        await deduper.ensure(null, body);
        await deduper.ensure(null, body);

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

        const body = {
            name: 'Captain Ahmed',
            employee_profile_template_id: null,
            idempotency_key: 'cccccccccccccccc',
        };

        await assert.rejects(() => deduper.ensure(null, body));
        const ensured = await deduper.ensure(null, body);

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

        const body = {
            name: 'Captain Ahmed',
            employee_profile_template_id: null,
            idempotency_key: 'dddddddddddddddd',
        };

        await deduper.ensure(null, body);
        const reused = await deduper.ensure(102, body);

        assert.equal(calls, 1);
        assert.equal(reused.id, 102);
    });

    it('posts idempotency_key with ensure requests', async () => {
        let requestBody: string | null = null;

        const ensured = await postEnsureEmployee(
            {
                name: 'Captain Ahmed',
                employee_profile_template_id: 7,
                idempotency_key: 'eeeeeeeeeeeeeeee',
            },
            async (_url, init) => {
                requestBody = String(init.body ?? '');

                return new Response(
                    JSON.stringify({
                        employee: {
                            id: 55,
                            name: 'Captain Ahmed',
                            employee_no: 'DRAFT-XYZXYZXY',
                        },
                    }),
                    { status: 200 },
                );
            },
        );

        assert.equal(ensured.id, 55);
        assert.match(requestBody ?? '', /"idempotency_key":"eeeeeeeeeeeeeeee"/);
        assert.match(requestBody ?? '', /"employee_profile_template_id":7/);
    });

    it('creates a stable-length idempotency key', () => {
        const key = createEnsureIdempotencyKey();

        assert.ok(key.length >= 16);
        assert.ok(key.length <= 64);
        assert.match(key, /^[A-Za-z0-9_-]+$/);
    });
});
