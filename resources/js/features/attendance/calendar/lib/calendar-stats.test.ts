import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CalendarLeave } from '../types.ts';
import { buildLeaveDayMap } from './build-leave-day-map.ts';
import { getCalendarStats } from './calendar-stats.ts';

function makeLeave(overrides: Partial<CalendarLeave> = {}): CalendarLeave {
    return {
        id: 1,
        status: 'approved',
        employee: { id: 10, name: 'John Doe', employee_no: 'EMP-10' },
        leave_type: {
            id: 1,
            name: 'Annual Leave',
            code: 'AL',
            color: '#2563eb',
            entitled_days: 30,
            used_days: 5,
            pending_days: 2,
            remaining_days: 23,
        },
        start_date: '2026-06-01',
        end_date: '2026-06-03',
        ...overrides,
    };
}

describe('calendar-stats and buildLeaveDayMap', () => {
    it('getCalendarStats counts only approved requests and approved leave days', () => {
        const approved = makeLeave({
            id: 1,
            status: 'approved',
            start_date: '2026-06-01',
            end_date: '2026-06-03',
        });
        const pending = makeLeave({
            id: 2,
            status: 'pending',
            start_date: '2026-06-10',
            end_date: '2026-06-12',
        });

        const stats = getCalendarStats([approved, pending], 2026);

        assert.equal(stats.requestCount, 1);
        assert.equal(stats.leaveDays, 3);
    });

    it('buildLeaveDayMap populates both approved and pending leaves per day', () => {
        const approved = makeLeave({
            id: 1,
            status: 'approved',
            start_date: '2026-06-01',
            end_date: '2026-06-02',
        });
        const pending = makeLeave({
            id: 2,
            status: 'pending',
            start_date: '2026-06-02',
            end_date: '2026-06-03',
        });

        const map = buildLeaveDayMap([approved, pending], 2026);

        assert.equal(map.get('2026-06-01')?.length, 1);
        assert.equal(map.get('2026-06-01')?.[0].status, 'approved');

        assert.equal(map.get('2026-06-02')?.length, 2);
        assert.equal(map.get('2026-06-02')?.[0].status, 'approved');
        assert.equal(map.get('2026-06-02')?.[1].status, 'pending');

        assert.equal(map.get('2026-06-03')?.length, 1);
        assert.equal(map.get('2026-06-03')?.[0].status, 'pending');
    });
});
