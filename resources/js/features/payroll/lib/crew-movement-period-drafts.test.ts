import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewTimesheet } from '../types.ts';
import {
    createEmptyMovementPeriodDraft,
    draftFromExistingSegment,
    inclusiveMovementDays,
    segmentDraftsFromTimesheet,
    splitMovementRangeAcrossPeriod,
} from './crew-movement-period-drafts.ts';

describe('crew movement period drafts', () => {
    it('loads existing segment values into edit form drafts without vessel/client/rank', () => {
        const timesheet = {
            segments: [
                {
                    id: 5,
                    sequence: 1,
                    pay_category: 'onsite',
                    pay_category_label: 'Onsite',
                    from_date: '2026-07-01',
                    to_date: '2026-07-10',
                    days: '10',
                    source: 'manual',
                    source_label: 'Manual',
                    crew_assignment_id: null,
                    assignment_no: null,
                    crew_assignment_phase_id: null,
                    remarks: 'Keep me',
                },
            ],
        } as CrewTimesheet;

        const drafts = segmentDraftsFromTimesheet(timesheet);

        assert.equal(drafts.length, 1);
        assert.equal(drafts[0]?.pay_category, 'onsite');
        assert.equal(drafts[0]?.from_date, '2026-07-01');
        assert.equal(drafts[0]?.to_date, '2026-07-10');
        assert.equal(drafts[0]?.remarks, 'Keep me');
    });

    it('creates empty draft row with default category', () => {
        const draft = createEmptyMovementPeriodDraft('new-1', 'onsite');

        assert.equal(draft.key, 'new-1');
        assert.equal(draft.pay_category, 'onsite');
        assert.equal(draft.from_date, '');
        assert.equal(draft.to_date, '');
        assert.equal(draft.remarks, '');
    });

    it('preserves existing segment data when converting to draft', () => {
        const segment = draftFromExistingSegment(
            {
                id: 9,
                sequence: 1,
                pay_category: 'sign_on_standby',
                pay_category_label: 'Sign-On Standby',
                from_date: '2026-07-01',
                to_date: '2026-07-05',
                days: '5',
                source: 'manual',
                source_label: 'Manual',
                crew_assignment_id: null,
                assignment_no: null,
                crew_assignment_phase_id: null,
                remarks: 'Standby note',
            },
            0,
        );

        assert.equal(segment.key, 'existing-9-0');
        assert.equal(segment.pay_category, 'sign_on_standby');
        assert.equal(segment.from_date, '2026-07-01');
        assert.equal(segment.to_date, '2026-07-05');
        assert.equal(segment.remarks, 'Standby note');
    });

    it('calculates inclusive movement days for validation display', () => {
        assert.equal(inclusiveMovementDays('2026-07-01', '2026-07-04'), 4);
        assert.equal(inclusiveMovementDays('2026-07-04', '2026-07-01'), null);
        assert.equal(inclusiveMovementDays('', '2026-07-01'), null);
    });

    it('splits movement ranges across the payroll period for arrears preview', () => {
        const split = splitMovementRangeAcrossPeriod(
            '2026-06-28',
            '2026-07-03',
            '2026-07-01',
            '2026-07-31',
        );

        assert.ok(split);
        assert.equal(split.priorDays, 3);
        assert.equal(split.currentDays, 3);
        assert.equal(split.prior?.from_date, '2026-06-28');
        assert.equal(split.prior?.to_date, '2026-06-30');
        assert.equal(split.current?.from_date, '2026-07-01');
        assert.equal(split.current?.to_date, '2026-07-03');
        assert.equal(split.exceedsPeriodEnd, false);
    });

    it('flags movement dates after the payroll period end', () => {
        const split = splitMovementRangeAcrossPeriod(
            '2026-07-28',
            '2026-08-02',
            '2026-07-01',
            '2026-07-31',
        );

        assert.ok(split);
        assert.equal(split.exceedsPeriodEnd, true);
        assert.equal(split.currentDays, 4);
        assert.equal(split.current?.to_date, '2026-07-31');
    });
});
