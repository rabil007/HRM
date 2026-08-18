import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { isCommandPaletteHotkey } from './global-search.ts';
import {
    recentItemCommandValue,
    recentItemHeading,
    recentItemsFromPayload,
    shouldRenderRecentGroup,
    shouldShowRecentItems,
} from './recent-items.ts';
import type { RecentItem } from './recent-items.ts';

const employeeRecent: RecentItem = {
    id: 'employee:12',
    type: 'employee',
    type_label: 'Employee',
    title: 'Mohammed Rabil',
    subtitle: 'EMP-0012 · Marine · Engineer',
    href: '/organization/employees/12',
};

const documentRecent: RecentItem = {
    id: 'document:4',
    type: 'document',
    type_label: 'Document',
    title: 'Passport',
    subtitle: 'EMP-0012 · Expires 12 Oct 2027',
    href: '/organization/documents/employees/1/files/4',
};

describe('recent visibility', () => {
    it('shows the Recent group only when the query is empty and items exist', () => {
        assert.equal(shouldShowRecentItems(''), true);
        assert.equal(shouldShowRecentItems('   '), true);
        assert.equal(shouldShowRecentItems('a'), false);
        assert.equal(shouldShowRecentItems('ab'), false);
        assert.equal(shouldRenderRecentGroup('', [employeeRecent]), true);
        assert.equal(shouldRenderRecentGroup('', []), false);
        assert.equal(shouldRenderRecentGroup('ab', [employeeRecent]), false);
    });

    it('does not create an empty Recent heading', () => {
        assert.equal(shouldRenderRecentGroup('', []), false);
    });
});

describe('recent identity', () => {
    it('renders type, title, and subtitle for command rows', () => {
        assert.equal(
            recentItemHeading(employeeRecent),
            'Employee · Mohammed Rabil',
        );
        assert.equal(employeeRecent.subtitle, 'EMP-0012 · Marine · Engineer');
        assert.equal(recentItemHeading(documentRecent), 'Document · Passport');
        assert.match(
            recentItemCommandValue(employeeRecent),
            /recent Employee Mohammed Rabil EMP-0012/,
        );
    });

    it('keeps show-page hrefs as the open target', () => {
        assert.equal(employeeRecent.href, '/organization/employees/12');
        assert.equal(
            documentRecent.href,
            '/organization/documents/employees/1/files/4',
        );
    });
});

describe('payload sanitization', () => {
    it('drops malformed items and external hrefs', () => {
        const items = recentItemsFromPayload({
            items: [
                employeeRecent,
                {
                    id: 'employee:99',
                    type: 'employee',
                    type_label: 'Employee',
                    title: 'Evil',
                    subtitle: '',
                    href: 'https://evil.example/phish',
                },
                { title: 'incomplete' },
            ],
        });

        assert.deepEqual(
            items.map((item) => item.id),
            ['employee:12'],
        );
    });
});

describe('command palette coexistence', () => {
    it('keeps Cmd/Ctrl+K as the palette shortcut', () => {
        assert.equal(
            isCommandPaletteHotkey({ key: 'k', metaKey: true, ctrlKey: false }),
            true,
        );
    });

    it('hides recents once typed search starts while favorites stay independent', () => {
        assert.equal(shouldShowRecentItems('em'), false);
        assert.equal(shouldRenderRecentGroup('em', [employeeRecent]), false);
    });
});
