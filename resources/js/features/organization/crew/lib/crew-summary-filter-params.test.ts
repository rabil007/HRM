import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildCrewSummaryFilterParams,
    queueSectionCopy,
    resolveActiveSummaryFilter,
    sanitizeFiltersForOperationalView,
} from './crew-summary-filter-params.ts';

const emptyFilters = {
    phase: '',
    status: '',
    vessel_id: '',
    rank_id: '',
    client_id: '',
    employee_id: '',
    planned_join_from: '',
    planned_join_to: '',
    planned_signoff_from: '',
    planned_signoff_to: '',
    movement_attention: false,
    include_completed: false,
    tour_status: '',
    relief_status: '',
    relief_risk: '',
    relief_not_ready: false,
    signoff_within_14_no_relief: false,
};

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

    it('clears conflicting filters for Current Assignments reset', () => {
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

    it('clears conflicting filters for On Home', () => {
        const params = buildCrewSummaryFilterParams('on_home', base);

        assert.equal(params.view, 'on_home');
        assert.equal(params.status, undefined);
        assert.equal(params.include_completed, undefined);
        assert.equal(params.phase, undefined);
    });
});

describe('resolveActiveSummaryFilter', () => {
    it('prioritizes Pre-Join Hotel over Needs Attention', () => {
        assert.equal(
            resolveActiveSummaryFilter(
                { ...emptyFilters, movement_attention: true },
                'pre_join_hotel',
            ),
            'pre_join_hotel',
        );
    });

    it('prioritizes Crew On-Site over Needs Attention', () => {
        assert.equal(
            resolveActiveSummaryFilter(
                { ...emptyFilters, movement_attention: true },
                'vessel',
            ),
            'crew_on_site',
        );
    });

    it('prioritizes Post-Sign-Off Hotel over Needs Attention', () => {
        assert.equal(
            resolveActiveSummaryFilter(
                { ...emptyFilters, movement_attention: true },
                'post_signoff_hotel',
            ),
            'post_signoff_hotel',
        );
    });

    it('selects Needs Attention on the default crew view', () => {
        assert.equal(
            resolveActiveSummaryFilter(
                { ...emptyFilters, movement_attention: true },
                'crew',
            ),
            'attention',
        );
    });

    it('selects Current Assignments on the default crew view without attention', () => {
        assert.equal(resolveActiveSummaryFilter(emptyFilters, 'crew'), '');
    });

    it('prioritizes On Home over Needs Attention', () => {
        assert.equal(
            resolveActiveSummaryFilter(
                { ...emptyFilters, movement_attention: true },
                'on_home',
            ),
            'on_home',
        );
    });
});

describe('queueSectionCopy', () => {
    it('uses neutral pre-join hotel copy', () => {
        const copy = queueSectionCopy('pre_join_hotel', emptyFilters);

        assert.equal(copy.title, 'Pre-Join Hotel crew');
        assert.match(copy.description, /active pre-join crew/i);
        assert.doesNotMatch(copy.description, /ready to join/i);
        assert.doesNotMatch(copy.description, /join standby or training/i);
    });

    it('keeps Pre-Join Hotel heading when Needs Attention is also enabled', () => {
        const copy = queueSectionCopy('pre_join_hotel', {
            ...emptyFilters,
            movement_attention: true,
        });

        assert.equal(copy.title, 'Pre-Join Hotel crew');
    });

    it('uses Needs Attention heading only on the default crew view', () => {
        const copy = queueSectionCopy('crew', {
            ...emptyFilters,
            movement_attention: true,
        });

        assert.equal(copy.title, 'Assignments needing attention');
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
