import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { getSidebarData } from './sidebar-data.ts';
import { getTopNavLinks } from './top-nav-data.ts';

function sidebarUrls(permissions: string[]): string[] {
    return getSidebarData(permissions).navGroups.flatMap((group) =>
        group.items.flatMap((item) => {
            if ('url' in item && item.url) {
                return [item.url];
            }

            return item.items?.map((subItem) => subItem.url) ?? [];
        }),
    );
}

describe('permission-aware navigation', () => {
    it('shows Users to view-only roles', () => {
        assert.ok(
            sidebarUrls(['users.view']).includes('/organization/users'),
        );
    });

    it('sends corrections-only crew roles to movement corrections', () => {
        const link = getTopNavLinks(
            ['crew_operations.corrections.view'],
            '/dashboard',
        ).find((item) => item.title === 'Crew Operations');

        assert.equal(link?.href, '/organization/crew-movement-corrections');
    });

    it('sends payroll overview-only roles to the overview', () => {
        const link = getTopNavLinks(
            ['payroll.overview.view'],
            '/dashboard',
        ).find((item) => item.title === 'Payroll');

        assert.equal(link?.href, '/payroll/overview');
    });
});
