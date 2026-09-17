import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

if (typeof globalThis.sessionStorage === 'undefined') {
    const store = new Map<string, string>();

    globalThis.sessionStorage = {
        get length() {
            return store.size;
        },
        clear: () => store.clear(),
        getItem: (key: string) => store.get(key) ?? null,
        key: (index: number) => [...store.keys()][index] ?? null,
        removeItem: (key: string) => {
            store.delete(key);
        },
        setItem: (key: string, value: string) => {
            store.set(key, value);
        },
    } as Storage;
}

import {
    clearBulkCreateSession,
    loadBulkCreateSession,
    saveBulkCreateSession,
} from './bulk-create-session.ts';

describe('bulk create session storage', () => {
    it('round-trips a bulk draft for the same company', () => {
        clearBulkCreateSession();

        saveBulkCreateSession(42, {
            form: {
                client_id: 1,
                vessel_id: 5,
                planned_join_at: '2026-11-01',
                remarks: 'Batch draft',
                crew: [{ employee_id: 10, rank_id: 1 }],
            },
            rowKeys: ['row-1'],
            bulkSidebarMode: 'preview',
            previewRowKey: 'row-1',
        });

        const restored = loadBulkCreateSession(42);

        assert.ok(restored);
        assert.equal(restored.form.crew.length, 1);
        assert.equal(restored.previewRowKey, 'row-1');
        assert.equal(restored.bulkSidebarMode, 'preview');

        clearBulkCreateSession();
    });

    it('ignores snapshots from another company', () => {
        clearBulkCreateSession();

        saveBulkCreateSession(42, {
            form: {
                client_id: null,
                vessel_id: null,
                planned_join_at: '',
                remarks: '',
                crew: [{ employee_id: null, rank_id: null }],
            },
            rowKeys: ['row-1'],
            bulkSidebarMode: 'summary',
            previewRowKey: null,
        });

        assert.equal(loadBulkCreateSession(99), null);

        clearBulkCreateSession();
    });
});
