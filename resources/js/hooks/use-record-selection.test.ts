import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    addSelectedIds,
    groupCheckboxState,
    headerCheckboxState,
    isAllVisibleSelected,
    isSomeVisibleSelected,
    removeSelectedIds,
    toggleSelectedId,
    toggleVisibleIds,
    visibleSelectedIds,
} from '../lib/record-selection.ts';

describe('record selection helpers', () => {
    it('intersects selected ids with currently visible ids', () => {
        assert.deepEqual(
            visibleSelectedIds(new Set([1, 2, 9]), [1, 2, 3]),
            [1, 2],
        );
        assert.deepEqual(visibleSelectedIds(new Set(['a', 'z']), ['a', 'b']), [
            'a',
        ]);
    });

    it('reports all-visible and partial header states', () => {
        assert.equal(isAllVisibleSelected(new Set([1, 2]), [1, 2]), true);
        assert.equal(isAllVisibleSelected(new Set([1]), [1, 2]), false);
        assert.equal(isAllVisibleSelected(new Set([1]), []), false);
        assert.equal(isSomeVisibleSelected(new Set([2]), [1, 2]), true);
        assert.equal(headerCheckboxState(true, false), true);
        assert.equal(headerCheckboxState(false, true), 'indeterminate');
        assert.equal(headerCheckboxState(false, false), false);
    });

    it('toggles a single id on and off', () => {
        const selected = toggleSelectedId(new Set<number>(), 7);

        assert.equal(selected.has(7), true);
        assert.equal(toggleSelectedId(selected, 7).has(7), false);
    });

    it('selects all visible ids, replacing the previous set', () => {
        assert.deepEqual([...toggleVisibleIds(new Set([9]), [1, 2])], [1, 2]);
    });

    it('clears the entire set when all visible ids are already selected', () => {
        assert.deepEqual([...toggleVisibleIds(new Set([1, 2]), [1, 2])], []);
    });

    it('selects and deselects groups without dropping unrelated ids', () => {
        const selected = addSelectedIds(new Set([1]), [2, 3]);

        assert.deepEqual(
            [...selected].sort((a, b) => a - b),
            [1, 2, 3],
        );
        assert.deepEqual(
            [...removeSelectedIds(selected, [2])].sort((a, b) => a - b),
            [1, 3],
        );
    });

    it('computes parent/group checkbox state from child ids', () => {
        const selected = new Set([10, 11]);

        assert.equal(groupCheckboxState(selected, []), false);
        assert.equal(groupCheckboxState(selected, [20, 21]), false);
        assert.equal(groupCheckboxState(selected, [10, 20]), 'indeterminate');
        assert.equal(groupCheckboxState(selected, [10, 11]), true);
    });

    it('does not expose stale ids after the visible list changes', () => {
        const selected = new Set([1, 2, 3]);

        assert.deepEqual(visibleSelectedIds(selected, [2]), [2]);
        assert.equal(isAllVisibleSelected(selected, [2, 9]), false);
    });

    it('keeps persistent selected ids when the visible list changes', () => {
        const selected = addSelectedIds(new Set<number>(), [1, 2]);

        assert.deepEqual(visibleSelectedIds(selected, [3, 4]), []);
        assert.deepEqual(
            [...selected].sort((a, b) => a - b),
            [1, 2],
        );
        assert.equal(isAllVisibleSelected(selected, [3, 4]), false);
    });
});
