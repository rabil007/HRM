import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { NAVIGATION_DESTINATIONS } from './navigation-favorites.ts';
import {
    applySavedViewFilters,
    captureCurrentFilters,
    isSupportedSavedViewPage,
    savedViewFilterKeys,
    savedViewFiltersMatch,
    SAVED_VIEW_PAGE_KEYS,
} from './saved-views.ts';

describe('saved view catalog', () => {
    it('is limited to the five operational list pages', () => {
        assert.deepEqual(SAVED_VIEW_PAGE_KEYS, [
            'employees',
            'documents',
            'crew',
            'leave',
            'payroll',
        ]);
        assert.equal(isSupportedSavedViewPage('employees'), true);
        assert.equal(isSupportedSavedViewPage('documents'), true);
        assert.equal(isSupportedSavedViewPage('crew'), true);
        assert.equal(isSupportedSavedViewPage('leave'), true);
        assert.equal(isSupportedSavedViewPage('payroll'), true);
        assert.equal(isSupportedSavedViewPage('branches'), false);
        assert.equal(isSupportedSavedViewPage('positions'), false);
        assert.equal(isSupportedSavedViewPage('organization.users'), false);
    });

    it('does not save pagination, csrf, urls, or identity keys', () => {
        const captured = captureCurrentFilters('employees', {
            search: 'marine',
            status: 'active',
            department_id: '12',
            page: 2,
            per_page: 50,
            sort: 'salary',
            direction: 'desc',
            company_id: '9',
            user_id: '4',
            _token: 'abc',
            url: '/organization/employees?hack=1',
            href: 'https://evil.example',
        });

        assert.deepEqual(captured, {
            search: 'marine',
            status: 'active',
            department_id: '12',
        });
        assert.equal('page' in captured, false);
        assert.equal('sort' in captured, false);
    });

    it('omits empty and default filter values', () => {
        assert.deepEqual(
            captureCurrentFilters('documents', {
                search: '  ',
                expiry: 'all',
                department_id: '',
            }),
            {},
        );
        assert.deepEqual(
            captureCurrentFilters('leave', {
                status: 'pending',
                scope: 'my',
            }),
            { status: 'pending' },
        );
        assert.deepEqual(
            captureCurrentFilters('crew', {
                view: 'crew',
                vessel_id: '15',
                relief_not_ready: false,
                include_completed: true,
            }),
            {
                vessel_id: '15',
                include_completed: '1',
            },
        );
    });

    it('applies stored filters as the current page query parameters', () => {
        assert.deepEqual(
            applySavedViewFilters('employees', {
                status: 'active',
                department_id: 12,
                old_filter: 'x',
            }),
            {
                status: 'active',
                department_id: '12',
            },
        );
        assert.deepEqual(
            applySavedViewFilters('payroll', {
                status: 'draft',
                salary: '9000',
            }),
            { status: 'draft' },
        );
        assert.equal(savedViewFilterKeys('payroll').includes('salary'), false);
    });

    it('treats matching captured and stored filters as the same view', () => {
        const current = captureCurrentFilters('crew', {
            search: 'horizon',
            phase: 'p4',
            vessel_id: '15',
            view: 'vessel',
        });
        const applied = applySavedViewFilters('crew', {
            search: 'horizon',
            phase: 'p4',
            vessel_id: '15',
            view: 'vessel',
        });

        assert.equal(savedViewFiltersMatch(current, applied), true);
        assert.equal(savedViewFiltersMatch(current, { phase: 'p4' }), false);
    });

    it('does not merge favorites or recents catalogs', () => {
        assert.equal(
            NAVIGATION_DESTINATIONS.some(
                (destination) => destination.key === 'saved-views',
            ),
            false,
        );
        assert.equal(
            SAVED_VIEW_PAGE_KEYS.includes('crew.current' as never),
            false,
        );
    });
});
