import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveEffectiveActiveGroup } from './role-permission-active-group.ts';

test('keeps the active group when it still exists in filtered results', () => {
    const grouped = [
        ['Employees', []],
        ['Crew Operations', []],
    ] as const;

    assert.equal(
        resolveEffectiveActiveGroup(grouped, 'Employees'),
        'Employees',
    );
});

test('switches to the first filtered group when the active group disappears', () => {
    const grouped = [['Crew Operations', []]] as const;

    assert.equal(
        resolveEffectiveActiveGroup(grouped, 'Employees'),
        'Crew Operations',
    );
});

test('returns null when no filtered groups remain', () => {
    assert.equal(resolveEffectiveActiveGroup([], 'Employees'), null);
});

test('selects the first group when no active group is set', () => {
    const grouped = [
        ['Announcements', []],
        ['Employees', []],
    ] as const;

    assert.equal(resolveEffectiveActiveGroup(grouped, null), 'Announcements');
});
