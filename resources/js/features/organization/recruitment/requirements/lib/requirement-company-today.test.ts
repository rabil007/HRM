import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { nowInCompanyDate } from '../../../../../lib/company-timezone.ts';
import type { FormPositionLineInput } from '../types.ts';
import { hydrateNewRequirementForm } from './requirement-form-hydration.ts';
import { shouldReinitializeRequirementForm } from './requirement-form-init.ts';

const defaultPositionLine: FormPositionLineInput = {
    position_id: '',
    required_headcount: 1,
    salary_min: '',
    salary_max: '',
    salary_currency_code: 'AED',
    line_notes: '',
};

describe('recruitment request received date defaults', () => {
    it('uses company-local today instead of UTC around Dubai midnight', () => {
        const instant = new Date('2026-10-15T20:30:00.000Z'); // 00:30 Asia/Dubai
        const companyToday = nowInCompanyDate('Asia/Dubai', instant);
        const utcToday = instant.toISOString().split('T')[0];

        assert.equal(companyToday, '2026-10-16');
        assert.equal(utcToday, '2026-10-15');
        assert.notEqual(companyToday, utcToday);
    });

    it('follows a different company timezone than the browser clock', () => {
        const instant = new Date('2026-10-16T02:00:00.000Z');
        assert.equal(
            nowInCompanyDate('America/New_York', instant),
            '2026-10-15',
        );
        assert.equal(nowInCompanyDate('Asia/Dubai', instant), '2026-10-16');
    });

    it('retains unsaved open-form values across a simulated midnight transition', () => {
        const openIdentity = {
            open: true,
            requirementId: null,
            companyTimezone: 'Asia/Dubai',
        };

        let draft = hydrateNewRequirementForm({
            today: '2026-10-15',
            defaultPositionLine,
        });
        draft = {
            ...draft,
            notes: 'keep me after midnight',
            request_received_date: '2026-10-14',
        };

        assert.equal(
            shouldReinitializeRequirementForm(openIdentity, openIdentity),
            false,
        );

        const wouldReset = hydrateNewRequirementForm({
            today: '2026-10-16',
            defaultPositionLine,
        });

        assert.equal(draft.notes, 'keep me after midnight');
        assert.equal(draft.request_received_date, '2026-10-14');
        assert.notEqual(
            draft.request_received_date,
            wouldReset.request_received_date,
        );
    });

    it('reinitializes when the sheet reopens or company context changes', () => {
        assert.equal(
            shouldReinitializeRequirementForm(
                {
                    open: false,
                    requirementId: null,
                    companyTimezone: 'Asia/Dubai',
                },
                {
                    open: true,
                    requirementId: null,
                    companyTimezone: 'Asia/Dubai',
                },
            ),
            true,
        );
        assert.equal(
            shouldReinitializeRequirementForm(
                {
                    open: true,
                    requirementId: null,
                    companyTimezone: 'Asia/Dubai',
                },
                {
                    open: true,
                    requirementId: null,
                    companyTimezone: 'Europe/London',
                },
            ),
            true,
        );
    });
});
