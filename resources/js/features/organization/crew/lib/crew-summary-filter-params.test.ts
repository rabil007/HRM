import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildCrewSummaryFilterParams,
    sanitizeFiltersForOperationalView,
} from './crew-summary-filter-params.ts';

describe('buildCrewSummaryFilterParams', () => {
    const base = {
        search: 'Ahmed',
        vessel_id: '12',
        rank_id: '3',
        status: 'completed',
        phase: 'p0',
        include_completed: true,
        movement_attention: true,
        per_page: 15,
    };

    it('clears conflicting filters for Active Assignments reset', () => {
        const params = buildCrewSummaryFilterParams('', base);

        assert.equal(params.view, undefined);
        assert.equal(params.phase, undefined);
        assert.equal(params.status, undefined);
        assert.equal(params.include_completed, undefined);
        assert.equal(params.movement_attention, undefined);
        assert.equal(params.search, 'Ahmed');
        assert.equal(params.vessel_id, '12');
    });

    it('uses crew view with movement_attention only for Needs Attention', () => {
        const params = buildCrewSummaryFilterParams('attention', base);

        assert.equal(params.view, undefined);
        assert.equal(params.movement_attention, true);
        assert.equal(params.status, undefined);
        assert.equal(params.include_completed, undefined);
        assert.equal(params.phase, undefined);
    });

    it('clears history filters for Pre-Join Hotel', () => {
        const params = buildCrewSummaryFilterParams('pre_join_hotel', base);

        assert.equal(params.view, 'pre_join_hotel');
        assert.equal(params.status, undefined);
        assert.equal(params.include_completed, undefined);
        assert.equal(params.phase, undefined);
    });

    it('clears completed status for Crew On-Site', () => {
        const params = buildCrewSummaryFilterParams('crew_on_site', base);

        assert.equal(params.view, 'vessel');
        assert.equal(params.status, undefined);
        assert.equal(params.include_completed, undefined);
    });

    it('clears conflicting filters for Post-Sign-Off Hotel', () => {
        const params = buildCrewSummaryFilterParams('post_signoff_hotel', base);

        assert.equal(params.view, 'post_signoff_hotel');
        assert.equal(params.status, undefined);
        assert.equal(params.include_completed, undefined);
        assert.equal(params.phase, undefined);
    });
});

describe('sanitizeFiltersForOperationalView', () => {
    it('clears phase status and include completed for operational location views', () => {
        const sanitized = sanitizeFiltersForOperationalView(
            {
                phase: 'p0',
                status: 'completed',
                include_completed: true,
                vessel_id: '1',
                rank_id: '',
                client_id: '',
                employee_id: '',
                planned_join_from: '',
                planned_join_to: '',
                planned_signoff_from: '',
                planned_signoff_to: '',
                movement_attention: false,
                tour_status: '',
                relief_status: '',
                relief_risk: '',
                relief_not_ready: false,
                signoff_within_14_no_relief: false,
            },
            'pre_join_hotel',
        );

        assert.equal(sanitized.phase, '');
        assert.equal(sanitized.status, '');
        assert.equal(sanitized.include_completed, false);
        assert.equal(sanitized.vessel_id, '1');
    });

    it('leaves filters unchanged on default crew view', () => {
        const filters = {
            phase: 'p0',
            status: 'completed',
            include_completed: true,
            vessel_id: '',
            rank_id: '',
            client_id: '',
            employee_id: '',
            planned_join_from: '',
            planned_join_to: '',
            planned_signoff_from: '',
            planned_signoff_to: '',
            movement_attention: false,
            tour_status: '',
            relief_status: '',
            relief_risk: '',
            relief_not_ready: false,
            signoff_within_14_no_relief: false,
        };

        assert.deepEqual(
            sanitizeFiltersForOperationalView(filters, 'crew'),
            filters,
        );
    });
});
